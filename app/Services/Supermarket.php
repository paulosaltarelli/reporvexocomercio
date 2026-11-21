<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SupermarketCart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class Supermarket
{
    public function actor(int $userId, string $permission = 'supermarket.use'): User
    {
        app(CompanyOperations::class)->lock();
        app(OperationalModes::class)->require('supermarket');
        $query = User::where('active', true);
        $user = (DB::connection('tenant')->transactionLevel() > 0 ? $query->lockForUpdate() : $query)->findOrFail($userId);
        if ($user->getRawOriginal('role') === User::ROLE_INSTALLER) InstallerAccess::applyGrant($user);
        abort_unless($user->allows($permission), 403);
        return $user;
    }

    public function lookup(string $code): Product
    {
        return $this->scan($code)['product'];
    }

    public function scan(string $code): array
    {
        $code = trim($code);
        $products = Product::where('active', true)->where('type', 'product')
            ->where(fn ($query) => $query->where('barcode', $code)->orWhere('sku', $code))
            ->orderBy('id')->limit(2)->get();
        if ($products->count() > 1) {
            throw ValidationException::withMessages(['code' => 'Este código corresponde a mais de um produto. Revise o cadastro.']);
        }
        if ($products->isNotEmpty()) return ['product' => $products->first(), 'label' => null, 'quantity' => null];

        $labels = app(WeightedLabels::class);
        $label = $labels->decode($code);
        if (!$label) throw ValidationException::withMessages(['code' => 'Produto não encontrado. Confira o código de barras ou SKU.']);
        $pluCodes = array_unique([$label['plu'], ltrim($label['plu'], '0') ?: '0']);
        $products = Product::where('active', true)->where('type', 'product')
            ->where(fn ($query) => $query->whereIn('sku', $pluCodes)->orWhereIn('barcode', $pluCodes))
            ->orderBy('id')->limit(2)->get();
        if ($products->count() !== 1) {
            throw ValidationException::withMessages(['code' => $products->isEmpty()
                ? 'O código PLU da etiqueta não possui produto ativo nesta loja.'
                : 'O código PLU da etiqueta corresponde a mais de um produto. Revise o cadastro.']);
        }
        $product = $products->first();
        if (strtoupper($product->unit) !== 'KG') {
            throw ValidationException::withMessages(['code' => 'Etiquetas pesáveis deste formato exigem produto com unidade de venda KG.']);
        }
        return ['product' => $product, 'label' => $label, 'quantity' => $labels->quantity($label, (string) $product->sale_price)];
    }

    public function start(string $requestKey, int $userId): SupermarketCart
    {
        return DB::connection('tenant')->transaction(function () use ($requestKey, $userId) {
            \Illuminate\Support\Facades\Validator::make(['request_key' => $requestKey], ['request_key' => 'required|uuid'])->validate();
            $this->actor($userId);
            $existing = SupermarketCart::where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless($existing->user_id === $userId, 404);
                return $existing;
            }
            if (SupermarketCart::where('user_id', $userId)->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['cart' => 'Conclua ou suspenda a venda aberta antes de iniciar outra.']);
            }
            $cart = SupermarketCart::create(['request_key' => $requestKey, 'user_id' => $userId, 'items' => []]);
            $this->record('supermarket_cart_open', $cart, $userId);
            return $cart;
        }, 3);
    }

    public function owned(int $cartId, int $userId, bool $lock = false): SupermarketCart
    {
        $query = SupermarketCart::where('user_id', $userId)->whereKey($cartId);
        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    private function editable(SupermarketCart $cart, int $version): void
    {
        if ($cart->version !== $version || $cart->status !== 'active') {
            throw ValidationException::withMessages(['version' => 'A venda mudou ou está suspensa. Atualize a tela para continuar.']);
        }
    }

    public function add(int $cartId, int $version, string $code, string $quantity, int $userId): SupermarketCart
    {
        return DB::connection('tenant')->transaction(function () use ($cartId, $version, $code, $quantity, $userId) {
            $this->actor($userId);
            $cart = $this->owned($cartId, $userId, true);
            $this->editable($cart, $version);
            $scan = $this->scan($code);
            $product = $scan['product'];
            $items = $cart->items;
            $quantity = Decimal::input($quantity, 3, 'quantity', '0.001', '999999999.999');
            if ($scan['label'] !== null && bccomp($quantity, '1', 3) !== 0) {
                throw ValidationException::withMessages(['quantity' => 'Para etiqueta pesável, mantenha a quantidade em 1 e leia cada embalagem separadamente.']);
            }
            $quantity = $scan['quantity'] ?? $quantity;
            $key = false;
            if ($scan['label'] === null) {
                foreach ($items as $index => $existing) {
                    if ($existing['product_id'] === $product->id && empty($existing['weighted_label'])) {
                        $key = $index;
                        break;
                    }
                }
            }
            if ($key !== false) {
                $quantity = Decimal::input(bcadd($items[$key]['quantity'], $quantity, 3), 3, 'quantity', '0.001', '999999999.999');
            } elseif (count($items) >= 200) {
                throw ValidationException::withMessages(['code' => 'Esta venda atingiu o limite de 200 linhas de itens.']);
            }
            $line = $this->line($product, $quantity, $key === false ? (string) Str::uuid() : $items[$key]['line_key']);
            if ($scan['label'] !== null) $line['weighted_label'] = $scan['label'];
            if ($key === false) $items[] = $line;
            else $items[$key] = $line;
            $updated = $this->saveItems($cart, $items, $userId, 'supermarket_item_add');
            $updated->lastScannedLineKey = $line['line_key'];
            return $updated;
        }, 3);
    }

    private function line(Product $product, string $quantity, string $lineKey): array
    {
        $terms = app(CommercialRules::class)->quote($product, ['unit_price' => $product->sale_price, 'quantity' => $quantity]);
        return ['line_key' => $lineKey, 'product_id' => $product->id, 'sku' => $product->sku,
            'description' => $product->name, 'unit' => $product->unit, 'quantity' => $terms['quantity'],
            'unit_price' => (string) $product->sale_price, 'total' => $terms['total']];
    }

    public function changeItem(int $cartId, int $version, string $lineKey, ?string $quantity, ?string $reason, int $userId): SupermarketCart
    {
        return DB::connection('tenant')->transaction(function () use ($cartId, $version, $lineKey, $quantity, $reason, $userId) {
            $this->actor($userId, $quantity === null ? 'supermarket.cancel' : 'supermarket.use');
            $cart = $this->owned($cartId, $userId, true);
            $this->editable($cart, $version);
            $items = $cart->items;
            $key = array_search($lineKey, array_column($items, 'line_key'), true);
            abort_if($key === false, 404);
            $previous = $items[$key];
            if ($quantity === null) {
                $this->reason($reason);
                unset($items[$key]);
            } else {
                if (!empty($items[$key]['weighted_label'])) {
                    throw ValidationException::withMessages(['quantity' => 'Retire a embalagem e leia a etiqueta novamente para alterar um item pesável.']);
                }
                $quantity = Decimal::input($quantity, 3, 'quantity', '0.001', '999999999.999');
                if (bccomp($quantity, $items[$key]['quantity'], 3) < 0) {
                    $this->actor($userId, 'supermarket.cancel');
                    $this->reason($reason);
                }
                $product = Product::where('active', true)->where('type', 'product')->findOrFail($items[$key]['product_id']);
                $items[$key] = $this->line($product, $quantity, $lineKey);
            }
            return $this->saveItems($cart, array_values($items), $userId, $quantity === null ? 'supermarket_item_remove' : 'supermarket_item_change', $reason, ['item' => $previous]);
        }, 3);
    }

    private function saveItems(SupermarketCart $cart, array $items, int $userId, string $action, ?string $reason = null, array $before = []): SupermarketCart
    {
        $total = array_reduce($items, fn ($sum, $line) => bcadd($sum, $line['total'], 2), '0.00');
        $cart->update(['items' => $items, 'total' => $total, 'version' => $cart->version + 1]);
        $this->record($action, $cart, $userId, $reason, $before);
        return $cart;
    }

    public function transition(int $cartId, int $version, string $status, ?string $reason, int $userId): SupermarketCart
    {
        return DB::connection('tenant')->transaction(function () use ($cartId, $version, $status, $reason, $userId) {
            $this->actor($userId, $status === 'cancelled' ? 'supermarket.cancel' : 'supermarket.use');
            if ($status === 'cancelled') $this->reason($reason);
            $cart = $this->owned($cartId, $userId, true);
            $allowed = ($status === 'active' && $cart->status === 'suspended')
                || ($status === 'suspended' && $cart->status === 'active')
                || ($status === 'cancelled' && in_array($cart->status, ['active', 'suspended'], true));
            if (!$allowed || $cart->version !== $version) {
                throw ValidationException::withMessages(['version' => 'A situação da venda mudou. Atualize a tela.']);
            }
            if ($status === 'active' && SupermarketCart::where('user_id', $userId)->where('status', 'active')->whereKeyNot($cart->id)->exists()) {
                throw ValidationException::withMessages(['cart' => 'Suspenda a venda atual antes de retomar outra.']);
            }
            $cart->update(['status' => $status, 'version' => $cart->version + 1, 'cancellation_reason' => $status === 'cancelled' ? $reason : null]);
            $this->record('supermarket_cart_'.$status, $cart, $userId, $reason);
            return $cart;
        }, 3);
    }

    public function checkout(int $cartId, int $version, array $payment, int $userId): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($cartId, $version, $payment, $userId) {
            $this->actor($userId, 'supermarket.checkout');
            $cart = $this->owned($cartId, $userId, true);
            if ($cart->status !== 'completed') $this->editable($cart, $version);
            if (!$cart->items) throw ValidationException::withMessages(['items' => 'Adicione produtos antes de concluir a venda.']);
            $products = $cart->status === 'completed' ? collect() : Product::whereIn('id', array_column($cart->items, 'product_id'))
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lines = [];
            foreach ($cart->items as $line) {
                if ($cart->status !== 'completed') {
                    $product = $products->get($line['product_id']);
                    if (!$product || !$product->active || $product->type !== 'product' || $product->unit !== $line['unit']) {
                        throw ValidationException::withMessages(['items' => 'Um produto foi alterado ou desativado. Retire o item e confira o cadastro antes de continuar.']);
                    }
                }
                $lines[] = ['kind' => 'product', 'id' => $line['product_id'], 'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'], 'catalog_price_seen' => $line['unit_price']];
            }
            unset($payment['version']);
            $data = $payment + ['paid' => false, 'payment_method' => 'cash', 'installments' => 1];
            $data['request_key'] = $cart->request_key;
            $data['items'] = $lines;
            $data['notes'] = 'Caixa supermercado #'.$cart->id;
            $sale = app(Checkout::class)->complete($data, $userId);
            if (!$cart->sale_id) {
                $cart->update(['sale_id' => $sale->id, 'customer_id' => $sale->customer_id, 'status' => 'completed', 'version' => $cart->version + 1]);
                $this->record('supermarket_cart_complete', $cart, $userId);
            }
            return $sale;
        }, 3);
    }

    private function reason(?string $reason): void
    {
        \Illuminate\Support\Facades\Validator::make(['reason' => $reason === null ? null : trim($reason)], ['reason' => 'required|string|min:3|max:1000'])->validate();
    }

    private function record(string $action, SupermarketCart $cart, int $userId, ?string $reason = null, array $before = []): void
    {
        OperationRecord::create(['action' => $action, 'version' => 1, 'user_id' => $userId, 'before' => $before,
            'after' => ['cart_id' => $cart->id, 'status' => $cart->status, 'version' => $cart->version, 'reason' => $reason === null ? null : trim($reason)], 'created_at' => now()]);
    }
}
