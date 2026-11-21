<?php

namespace App\Services;

use App\Models\{Customer, OperationRecord, Product, RestaurantOrder, RestaurantOrderItem, RestaurantTable, Sale};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RestaurantOperations
{
    public const STATUSES = ['draft' => 'A lançar', 'queued' => 'Na fila', 'preparing' => 'Em preparo', 'ready' => 'Pronto', 'delivered' => 'Entregue', 'cancelled' => 'Cancelado'];

    private function transaction(callable $operation)
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($operation) {
                app(CompanyOperations::class)->lock();
                app(OperationalModes::class)->require('restaurant');
                return $operation();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['request_key' => 'Esta operação já foi registrada. Atualize a página e confira o atendimento antes de continuar.']);
        }
    }

    public function createTable(array $input, int $actor): RestaurantTable
    {
        $data = Validator::make($input, ['name' => 'required|string|max:80|unique:tenant.restaurant_tables,name'])->validate();
        return $this->transaction(function () use ($data, $actor) {
            $table = RestaurantTable::create($data);
            $this->audit('restaurant_table', $actor, ['table_id' => $table->id, 'name' => $table->name]);
            return $table;
        });
    }

    public function open(array $input, int $actor): RestaurantOrder
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'table_id' => 'required|integer', 'customer_id' => 'nullable|integer'])->validate();
        return $this->transaction(function () use ($data, $actor) {
            $table = RestaurantTable::where('active', true)->lockForUpdate()->findOrFail($data['table_id']);
            $existing = RestaurantOrder::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->restaurant_table_id !== $table->id || $existing->user_id !== $actor || (int)$existing->customer_id !== (int)($data['customer_id'] ?? 0)) {
                    throw ValidationException::withMessages(['request_key' => 'Esta tentativa já pertence a outro atendimento.']);
                }
                return $existing;
            }
            if (RestaurantOrder::where('restaurant_table_id', $table->id)->where('status', 'open')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['table_id' => 'Esta mesa já possui uma comanda aberta. Abra o atendimento existente.']);
            }
            if (!empty($data['customer_id'])) Customer::findOrFail($data['customer_id']);
            $order = RestaurantOrder::create(['request_key' => $data['request_key'], 'restaurant_table_id' => $table->id,
                'customer_id' => $data['customer_id'] ?? null, 'user_id' => $actor, 'status' => 'open']);
            $this->audit('restaurant_open', $actor, ['order_id' => $order->id, 'table_id' => $table->id]);
            return $order;
        });
    }

    private function locked(int $id): RestaurantOrder
    {
        $reference = RestaurantOrder::findOrFail($id);
        RestaurantTable::lockForUpdate()->findOrFail($reference->restaurant_table_id);
        return RestaurantOrder::lockForUpdate()->findOrFail($id);
    }

    private function editable(RestaurantOrder $order, array $input): void
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        if ($order->status !== 'open') throw ValidationException::withMessages(['order' => 'Esta comanda já foi encerrada.']);
        if ($order->version !== (int)$data['version']) throw ValidationException::withMessages(['version' => 'A comanda foi alterada. Atualize a página e confira os itens antes de continuar.']);
    }

    public function addItem(int $id, array $input, int $actor): RestaurantOrderItem
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'product_id' => 'required|integer',
            'quantity' => 'required', 'notes' => 'nullable|string|max:1000', 'station' => 'required|in:kitchen,bar'])->validate();
        $quantity = Decimal::input((string)$data['quantity'], 3, 'quantity', '0.001', '999999.999');
        return $this->transaction(function () use ($id, $input, $data, $quantity, $actor) {
            $order = $this->locked($id);
            $existing = RestaurantOrderItem::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->restaurant_order_id !== $order->id || $existing->user_id !== $actor || $existing->product_id !== (int)$data['product_id']
                    || bccomp($existing->quantity, $quantity, 3) !== 0 || $existing->station !== $data['station'] || ($existing->notes ?? '') !== ($data['notes'] ?? '')) {
                    throw ValidationException::withMessages(['request_key' => 'A tentativa já foi usada para outro lançamento.']);
                }
                return $existing;
            }
            $this->editable($order, $input);
            if ($order->items()->where('status', '<>', 'cancelled')->count() >= 200) throw ValidationException::withMessages(['items' => 'Esta comanda atingiu o limite de 200 lançamentos.']);
            $product = Product::with('group')->where('active', true)->where('type', 'product')->sharedLock()->findOrFail($data['product_id']);
            app(CompanyOperations::class)->assertItem($product->type);
            $terms = app(CommercialRules::class)->quote($product, ['quantity' => $quantity, 'unit_price' => $product->sale_price]);
            $item = $order->items()->create(['request_key' => $data['request_key'], 'product_id' => $product->id, 'description' => $product->name,
                'quantity' => $quantity, 'unit_price' => $product->sale_price, 'station' => $data['station'], 'notes' => $data['notes'] ?? null,
                'status' => 'draft', 'user_id' => $actor, 'commercial_terms' => $terms]);
            $order->increment('version');
            $this->audit('restaurant_add', $actor, ['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => $item->unit_price]);
            return $item;
        });
    }

    public function send(int $id, array $input, int $actor): void
    {
        $this->transaction(function () use ($id, $input, $actor) {
            $order = $this->locked($id);
            $this->editable($order, $input);
            $ids = $order->items()->where('status', 'draft')->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) throw ValidationException::withMessages(['items' => 'Adicione itens antes de enviar à produção.']);
            $order->items()->whereIn('id', $ids)->update(['status' => 'queued', 'sent_at' => now(), 'updated_at' => now()]);
            $order->increment('version');
            $this->audit('restaurant_send', $actor, ['order_id' => $order->id, 'item_ids' => $ids->all()]);
        });
    }

    public function transitionItem(int $id, int $itemId, array $input, int $actor): void
    {
        $data = Validator::make($input, ['status' => 'required|in:preparing,ready'])->validate();
        $this->changeItemState($id, $itemId, $input, $data['status'], $actor);
    }

    public function deliverItem(int $id, int $itemId, array $input, int $actor): void
    {
        $this->changeItemState($id, $itemId, $input, 'delivered', $actor);
    }

    private function changeItemState(int $id, int $itemId, array $input, string $status, int $actor): void
    {
        $this->transaction(function () use ($id, $itemId, $input, $status, $actor) {
            $order = $this->locked($id);
            $this->editable($order, $input);
            $item = $order->items()->lockForUpdate()->findOrFail($itemId);
            $next = ['queued' => 'preparing', 'preparing' => 'ready', 'ready' => 'delivered'];
            if (($next[$item->status] ?? null) !== $status) throw ValidationException::withMessages(['status' => 'Confira a etapa atual do item antes de avançar.']);
            $before = $item->status;
            $item->update(['status' => $status]);
            $order->increment('version');
            $this->audit('restaurant_status', $actor, ['order_id' => $order->id, 'item_id' => $item->id, 'from' => $before, 'status' => $item->status]);
        });
    }

    public function cancelItem(int $id, int $itemId, array $input, int $actor): void
    {
        $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
        $this->transaction(function () use ($id, $itemId, $input, $data, $actor) {
            $order = $this->locked($id);
            $this->editable($order, $input);
            $item = $order->items()->lockForUpdate()->findOrFail($itemId);
            if ($item->status === 'cancelled') throw ValidationException::withMessages(['item' => 'Este item já está cancelado.']);
            $item->update(['status' => 'cancelled', 'cancellation_reason' => $data['reason']]);
            $order->increment('version');
            $this->audit('restaurant_cancel_item', $actor, ['order_id' => $order->id, 'item_id' => $item->id, 'reason' => $data['reason']]);
        });
    }

    public function close(int $id, array $input, int $actor): Sale
    {
        return $this->transaction(function () use ($id, $input, $actor) {
            $order = $this->locked($id);
            $items = $order->items()->where('status', '<>', 'cancelled')->orderBy('id')->get();
            $payment = Validator::make($input, ['payment_option' => 'nullable|string|max:60', 'payment_method' => 'required|string|max:40', 'paid' => 'required|boolean',
                'due_date' => 'nullable|date', 'installments' => 'nullable|integer|min:1|max:120', 'salesperson_id' => 'nullable|integer',
                'payments' => 'sometimes|array|min:1|max:20', 'payments.*' => 'array', 'card_brand' => 'nullable|string', 'card_installments' => 'nullable|integer|min:1|max:36'])->validate();
            if (!$order->sale_id) {
                $this->editable($order, $input);
                if ($items->isEmpty()) throw ValidationException::withMessages(['items' => 'A comanda precisa de ao menos um item para fechar a conta.']);
                if ($items->contains(fn ($item) => $item->status !== 'delivered')) throw ValidationException::withMessages(['items' => 'Entregue os itens ou cancele os lançamentos pendentes antes de fechar a conta.']);
            }
            $sale = app(Checkout::class)->completeFromRestaurant($order->id, $payment, $actor);
            if ($order->sale_id && $order->sale_id !== $sale->id) throw new \LogicException('A comanda já está vinculada a outra venda.');
            if (!$order->sale_id) {
                $order->update(['status' => 'closed', 'sale_id' => $sale->id, 'closed_at' => now(), 'version' => $order->version + 1]);
                $this->audit('restaurant_close', $actor, ['order_id' => $order->id, 'sale_id' => $sale->id]);
            }
            return $sale;
        });
    }

    public function cancel(int $id, array $input, int $actor): void
    {
        $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
        $this->transaction(function () use ($id, $input, $data, $actor) {
            $order = $this->locked($id);
            $this->editable($order, $input);
            if ($order->items()->where('status', '<>', 'cancelled')->exists()) throw ValidationException::withMessages(['items' => 'Cancele os itens individualmente antes de cancelar a comanda.']);
            $order->update(['status' => 'cancelled', 'closed_at' => now(), 'version' => $order->version + 1]);
            $this->audit('restaurant_cancel', $actor, ['order_id' => $order->id, 'reason' => $data['reason']]);
        });
    }

    private function audit(string $action, int $actor, array $after): void
    {
        OperationRecord::create(['action' => $action, 'version' => 1, 'user_id' => $actor, 'before' => [], 'after' => $after, 'created_at' => now()]);
    }
}
