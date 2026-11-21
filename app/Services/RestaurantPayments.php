<?php

namespace App\Services;

use App\Models\{FinancialEntry, OperationRecord, Payment, RestaurantOrder, RestaurantOrderItem, RestaurantOrderPayment, RestaurantOrderShare, RestaurantPaymentItem, RestaurantTable, Sale};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class RestaurantPayments
{
    public function hasReceipts(int $orderId): bool
    {
        return RestaurantOrderPayment::where('restaurant_order_id', $orderId)->whereNull('refunded_at')->exists();
    }

    public function headerChoice(int $orderId): array
    {
        $receipt = RestaurantOrderPayment::where('restaurant_order_id', $orderId)->whereNull('refunded_at')->orderBy('id')->firstOrFail();
        $snapshot = $receipt->payment_snapshot;
        return ['id' => $snapshot['id'], 'label' => $snapshot['label'], 'type' => $snapshot['type'], 'active' => true];
    }

    public function summary(RestaurantOrder $order): array
    {
        $items = $order->items()->where('status', '<>', 'cancelled')->get();
        $payments = RestaurantOrderPayment::with('allocations', 'share')->where('restaurant_order_id', $order->id)->orderBy('id')->get();
        $active = $payments->whereNull('refunded_at');
        $total = $items->reduce(fn ($sum, $item) => bcadd($sum, (string)$item->commercial_terms['total'], 2), '0.00');
        $baseReceived = $active->reduce(fn ($sum, $payment) => bcadd($sum, $payment->base_amount, 2), '0.00');
        $received = $active->reduce(fn ($sum, $payment) => bcadd($sum, $payment->amount, 2), '0.00');
        $passed = $active->reduce(fn ($sum, $payment) => bcadd($sum, $payment->passed_fee, 2), '0.00');
        $remainingItems = $items->map(function ($item) use ($active) {
            $allocations = $active->flatMap->allocations->where('restaurant_order_item_id', $item->id);
            return ['item_id' => $item->id, 'description' => $item->description, 'quantity' => $item->quantity,
                'remaining_quantity' => bcsub($item->quantity, $allocations->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0.000'), 3),
                'amount' => (string)$item->commercial_terms['total'],
                'remaining_amount' => bcsub((string)$item->commercial_terms['total'], $allocations->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00'), 2)];
        })->values()->all();
        $shares = RestaurantOrderShare::where('restaurant_order_id', $order->id)->whereNull('cancelled_at')->orderBy('id')->get()->map(function ($share) use ($active) {
            $paid = $active->where('share_id', $share->id)->reduce(fn ($sum, $payment) => bcadd($sum, $payment->base_amount, 2), '0.00');
            return ['id' => $share->id, 'division_key' => $share->division_key, 'person_name' => $share->person_name,
                'amount' => $share->amount, 'received' => $paid, 'balance' => bcsub($share->amount, $paid, 2)];
        })->all();
        $balance = bcsub($total, $baseReceived, 2);
        $grandTotal = bcadd($total, $passed, 2);
        if ($order->sale_id) {
            $grandTotal = (string)$order->sale->total;
            $passed = app(SalePayments::class)->passedTotal($order->sale_id);
            $entries = FinancialEntry::where('sale_id', $order->sale_id)->where('direction', 'in')->whereNull('cancelled_at')->get();
            $received = $entries->reduce(fn ($sum, $entry) => bcadd($sum, $entry->paid_amount, 2), '0.00');
            $balance = $entries->reduce(fn ($sum, $entry) => bcadd($sum, bcsub($entry->amount, $entry->paid_amount, 2), 2), '0.00');
        }
        return ['total' => $total, 'grand_total' => $grandTotal, 'received' => $received,
            'base_received' => $baseReceived, 'balance' => $balance, 'passed_fee' => $passed,
            'payments' => $payments, 'shares' => $shares, 'item_remaining' => $remainingItems];
    }

    private function transaction(int $actor, string $permission, callable $operation)
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($actor, $permission, $operation) {
                app(CompanyOperations::class)->lock();
                app(OperationalModes::class)->require('restaurant');
                InstallerAccess::authorizedActor($actor, $permission);
                return $operation();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['request_key' => 'Esta tentativa já foi registrada. Atualize a comanda e confira o recebimento.']);
        }
    }

    private function lockOrder(int $id): RestaurantOrder
    {
        $reference = RestaurantOrder::findOrFail($id);
        RestaurantTable::lockForUpdate()->findOrFail($reference->restaurant_table_id);
        $order = RestaurantOrder::lockForUpdate()->findOrFail($id);
        if ($reference->restaurant_table_id !== $order->restaurant_table_id) throw ValidationException::withMessages(['version' => 'A mesa mudou. Atualize a comanda.']);
        return $order;
    }

    private function editable(RestaurantOrder $order, array $input): void
    {
        $data = Validator::make($input, ['version' => 'required|integer|min:1'])->validate();
        if ($order->status !== 'open') throw ValidationException::withMessages(['order' => 'A comanda já está encerrada. Consulte os recebimentos na venda.']);
        if ($order->version !== (int)$data['version']) throw ValidationException::withMessages(['version' => 'A comanda mudou. Atualize e confira o saldo antes de continuar.']);
    }

    public function divide(int $orderId, array $input, int $actor): array
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'people_count' => 'required|integer|min:1|max:100',
            'names' => 'sometimes|array|max:100', 'names.*' => 'nullable|string|max:100'])->validate();
        return $this->transaction($actor, 'restaurant.receive', function () use ($orderId, $input, $data, $actor) {
            $order = $this->lockOrder($orderId);
            $names = [];
            for ($index = 1; $index <= (int)$data['people_count']; $index++) $names[] = trim((string)($data['names'][$index - 1] ?? '')) ?: 'Pessoa '.$index;
            $existing = RestaurantOrderShare::where('division_key', $data['request_key'])->orderBy('share_index')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                if ($existing->count() !== count($names) || $existing->contains(fn ($share) => $share->restaurant_order_id !== $order->id)
                    || $existing->pluck('person_name')->all() !== $names) throw ValidationException::withMessages(['request_key' => 'A tentativa já pertence a outra divisão.']);
                return $existing->all();
            }
            $this->editable($order, $input);
            $summary = $this->summary($order);
            if (collect($summary['shares'])->contains(fn ($share) => bccomp($share['balance'], '0', 2) > 0)) throw ValidationException::withMessages(['people_count' => 'Já existe uma divisão em aberto. Cancele as partes não recebidas antes de dividir novamente.']);
            $cents = bcmul($summary['balance'], '100', 0);
            if (bccomp($cents, (string)count($names), 0) < 0) throw ValidationException::withMessages(['people_count' => 'Cada pessoa precisa ter ao menos um centavo para pagar.']);
            $base = bcdiv($cents, (string)count($names), 0);
            $rows = [];
            foreach ($names as $index => $name) {
                $part = $index === count($names) - 1 ? bcsub($cents, bcmul($base, (string)$index, 0), 0) : $base;
                $rows[] = RestaurantOrderShare::create(['restaurant_order_id' => $order->id, 'division_key' => $data['request_key'],
                    'share_index' => $index + 1, 'person_name' => $name, 'amount' => bcdiv($part, '100', 2)]);
            }
            $order->increment('version');
            $this->audit('restaurant_divide', $actor, ['order_id' => $order->id, 'division_key' => $data['request_key'], 'share_ids' => array_map(fn ($row) => $row->id, $rows)]);
            return $rows;
        });
    }

    public function cancelDivision(int $orderId, string $division, array $input, int $actor): void
    {
        $data = Validator::make($input, ['reason' => 'required|string|min:3|max:1000'])->validate();
        $this->transaction($actor, 'restaurant.receive', function () use ($orderId, $division, $input, $data, $actor) {
            $order = $this->lockOrder($orderId);
            $this->editable($order, $input);
            $shares = RestaurantOrderShare::where('restaurant_order_id', $order->id)->where('division_key', $division)->lockForUpdate()->get();
            abort_if($shares->isEmpty(), 404);
            foreach ($shares as $share) $share->update(['cancelled_at' => now()]);
            $order->increment('version');
            $this->audit('restaurant_divide_cancel', $actor, ['order_id' => $order->id, 'division_key' => $division, 'reason' => $data['reason']]);
        });
    }

    private function allocation(RestaurantOrder $order, array $data): array
    {
        $amount = $data['amount'] ?? null;
        $rows = [];
        $share = null;
        if ($data['allocation_type'] === 'person') {
            $share = RestaurantOrderShare::where('restaurant_order_id', $order->id)->whereNull('cancelled_at')->lockForUpdate()->findOrFail($data['share_id'] ?? 0);
            $already = RestaurantOrderPayment::where('share_id', $share->id)->whereNull('refunded_at')->get()->reduce(fn ($sum, $row) => bcadd($sum, $row->base_amount, 2), '0.00');
            $balance = bcsub($share->amount, $already, 2);
            $amount ??= $balance;
            if (bccomp((string)$amount, $balance, 2) > 0) throw ValidationException::withMessages(['amount' => 'O valor ultrapassa o saldo desta pessoa.']);
        }
        if ($data['allocation_type'] === 'items') {
            $amount = '0.00';
            foreach ($data['items'] ?? [] as $part) {
                $item = $order->items()->where('status', '<>', 'cancelled')->lockForUpdate()->findOrFail($part['item_id']);
                $previous = RestaurantPaymentItem::where('restaurant_order_item_id', $item->id)
                    ->whereHas('receipt', fn ($query) => $query->whereNull('refunded_at'))->get();
                $paidQuantity = $previous->reduce(fn ($sum, $row) => bcadd($sum, $row->quantity, 3), '0.000');
                $paidAmount = $previous->reduce(fn ($sum, $row) => bcadd($sum, $row->amount, 2), '0.00');
                $remaining = bcsub($item->quantity, $paidQuantity, 3);
                $quantity = Decimal::input((string)$part['quantity'], 3, 'items', '0.001', $remaining);
                $lineTotal = (string)$item->commercial_terms['total'];
                $value = bccomp($quantity, $remaining, 3) === 0 ? bcsub($lineTotal, $paidAmount, 2)
                    : Decimal::round(bcdiv(bcmul($lineTotal, $quantity, 8), $item->quantity, 8), 2);
                $rows[] = ['restaurant_order_item_id' => $item->id, 'quantity' => $quantity, 'amount' => $value,
                    'description' => $item->description, 'unit_price' => $item->unit_price];
                $amount = bcadd($amount, $value, 2);
            }
            if (isset($data['amount']) && bccomp($data['amount'], $amount, 2) !== 0) throw ValidationException::withMessages(['amount' => 'O valor deve corresponder aos itens selecionados.']);
        }
        return [Decimal::input((string)$amount, 2, 'amount', '0.01'), $share, $rows];
    }

    public function receive(int $orderId, array $input, int $actor): RestaurantOrderPayment
    {
        $data = Validator::make($input, array_merge(CardFees::rules(), [
            'request_key' => 'required|uuid', 'payment_option' => 'required|string|max:60', 'amount' => 'nullable|string|max:24',
            'allocation_type' => 'required|in:amount,person,items', 'share_id' => 'required_if:allocation_type,person|nullable|integer',
            'items' => 'required_if:allocation_type,items|array|max:200', 'items.*.item_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|string|max:24', 'cash_received' => 'nullable|string|max:24',
        ]))->validate();
        if (isset($data['amount'])) $data['amount'] = Decimal::input($data['amount'], 2, 'amount', '0.01');
        $fingerprintData = $data;
        unset($fingerprintData['request_key']);
        ksort($fingerprintData);
        $fingerprint = hash('sha256', json_encode($fingerprintData, JSON_THROW_ON_ERROR));
        return $this->transaction($actor, 'restaurant.receive', function () use ($orderId, $input, $data, $actor, $fingerprint) {
            $order = $this->lockOrder($orderId);
            $existing = RestaurantOrderPayment::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->restaurant_order_id !== $order->id || $existing->user_id !== $actor || $existing->payment_snapshot['request_fingerprint'] !== $fingerprint) {
                    throw ValidationException::withMessages(['request_key' => 'Esta tentativa já pertence a outro recebimento.']);
                }
                return $existing;
            }
            $this->editable($order, $input);
            $receiptCount = RestaurantOrderPayment::where('restaurant_order_id', $order->id)->whereNull('refunded_at')->count();
            if ($receiptCount >= 200) throw ValidationException::withMessages(['payments' => 'A comanda atingiu o limite de 200 recebimentos.']);
            [$base, $share, $allocations] = $this->allocation($order, $data);
            $summary = $this->summary($order);
            if (bccomp($base, $summary['balance'], 2) > 0) throw ValidationException::withMessages(['amount' => 'O recebimento ultrapassa o saldo da comanda.']);
            if ($receiptCount === 199 && bccomp($base, $summary['balance'], 2) < 0) {
                throw ValidationException::withMessages(['amount' => 'Este é o último recebimento disponível. Receba o saldo integral da comanda para permitir seu fechamento.']);
            }
            $choice = app(PaymentMethods::class)->resolve($data['payment_option'], 'cash');
            if ($choice['type'] === 'invoice') throw ValidationException::withMessages(['payment_option' => 'Selecione o meio efetivamente recebido. A prazo permanece a receber no fechamento.']);
            $quote = app(CardFees::class)->quote($base, $choice['type'], $data);
            $tendered = null;
            $change = '0.00';
            if ($choice['type'] === 'cash' && isset($data['cash_received'])) {
                $tendered = Decimal::input($data['cash_received'], 2, 'cash_received', $quote['amount']);
                $change = bcsub($tendered, $quote['amount'], 2);
            }
            $finance = app(FinancialService::class);
            $entry = $finance->schedule(['request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant-receipt:'.$data['request_key'])->toString(),
                'amount' => $quote['amount'], 'direction' => 'in', 'description' => 'Recebimento da comanda #'.$order->id,
                'category' => 'Consumo restaurante', 'due_date' => now()->toDateString(), 'installments' => 1], $actor)->first();
            $payment = $finance->pay($entry->id, $entry->amount, now()->toDateString(), $choice['type'], $actor,
                Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant-received:'.$data['request_key'])->toString(), $choice['id']);
            $feeEntry = null;
            if (bccomp($quote['fee'], '0', 2) > 0) {
                $feeEntry = $finance->schedule(['request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant-fee:'.$data['request_key'])->toString(),
                    'amount' => $quote['fee'], 'direction' => 'out', 'description' => 'Taxa de cartão · comanda #'.$order->id,
                    'category' => 'Taxas de cartão', 'due_date' => now()->toDateString(), 'installments' => 1], $actor)->first();
                $finance->pay($feeEntry->id, $feeEntry->amount, now()->toDateString(), $choice['type'], $actor,
                    Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant-fee-paid:'.$data['request_key'])->toString(), $choice['id']);
            }
            $snapshot = $choice + $quote + ['paid' => true, 'due_date' => now()->toDateString(), 'installments' => 1,
                'cash_received' => $tendered, 'change' => $change, 'request_fingerprint' => $fingerprint,
                'fee_entry_ids' => $feeEntry ? [$feeEntry->id] : []];
            $receipt = RestaurantOrderPayment::create(['restaurant_order_id' => $order->id, 'request_key' => $data['request_key'],
                'financial_entry_id' => $entry->id, 'payment_id' => $payment->id, 'share_id' => $share?->id, 'fee_entry_id' => $feeEntry?->id,
                'base_amount' => $base, 'amount' => $quote['amount'], 'fee' => $quote['fee'], 'passed_fee' => $quote['passed_fee'],
                'payment_snapshot' => $snapshot, 'allocation_type' => $data['allocation_type'], 'user_id' => $actor]);
            foreach ($allocations as $row) $receipt->allocations()->create($row);
            $order->increment('version');
            $this->audit('restaurant_receive', $actor, ['order_id' => $order->id, 'receipt_id' => $receipt->id, 'payment_id' => $payment->id,
                'amount' => $receipt->amount, 'base_amount' => $base, 'share_id' => $share?->id]);
            return $receipt;
        });
    }

    public function refund(int $orderId, int $receiptId, array $input, int $actor): RestaurantOrderPayment
    {
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'refund_confirmed' => 'required|accepted', 'reason' => 'required|string|min:3|max:1000'])->validate();
        return $this->transaction($actor, 'restaurant.refund', function () use ($orderId, $receiptId, $input, $data, $actor) {
            $order = $this->lockOrder($orderId);
            $receipt = RestaurantOrderPayment::where('restaurant_order_id', $order->id)->lockForUpdate()->findOrFail($receiptId);
            if ($receipt->refunded_at) {
                if ($receipt->refund_request_key !== $data['request_key'] || $receipt->refund_reason !== $data['reason']) throw ValidationException::withMessages(['request_key' => 'O recebimento já foi estornado.']);
                return $receipt;
            }
            $this->editable($order, $input);
            $entry = FinancialEntry::lockForUpdate()->findOrFail($receipt->financial_entry_id);
            app(CashService::class)->lockOpenDay(now()->toDateString());
            $payment = Payment::lockForUpdate()->findOrFail($receipt->payment_id);
            if ($entry->sale_id || $entry->cancelled_at || $payment->kind !== 'payment' || $payment->entry_id !== $entry->id
                || bccomp($entry->paid_amount, $receipt->amount, 2) !== 0 || $payment->reversal()->exists()) {
                throw ValidationException::withMessages(['payment' => 'O recebimento precisa de conferência financeira antes do estorno.']);
            }
            $refund = Payment::create(['request_key' => $data['request_key'], 'entry_id' => $entry->id, 'user_id' => $actor,
                'amount' => $payment->amount, 'paid_on' => now()->toDateString(), 'method' => $payment->method,
                'direction' => 'out', 'kind' => 'refund', 'reversal_of' => $payment->id]);
            $choice = $receipt->payment_snapshot;
            app(PaymentMethods::class)->snapshot('payment_method_used', $refund->id,
                ['id' => $choice['id'], 'label' => $choice['label'], 'type' => $choice['type']], $actor);
            $entry->update(['paid_amount' => '0.00', 'paid_at' => null, 'cancelled_at' => now()]);
            $receipt->update(['refunded_at' => now(), 'refund_payment_id' => $refund->id, 'refund_request_key' => $data['request_key'], 'refund_reason' => $data['reason']]);
            $order->increment('version');
            $this->audit('restaurant_refund', $actor, ['order_id' => $order->id, 'receipt_id' => $receipt->id, 'payment_id' => $refund->id, 'reason' => $data['reason']]);
            return $receipt;
        });
    }

    public function assertCanCancelItem(RestaurantOrder $order, RestaurantOrderItem $item): void
    {
        if (RestaurantPaymentItem::where('restaurant_order_item_id', $item->id)->whereHas('receipt', fn ($query) => $query->whereNull('refunded_at'))->exists()) {
            throw ValidationException::withMessages(['item' => 'Este item possui recebimento alocado. Estorne o recebimento antes de cancelar.']);
        }
        $after = bcsub($this->summary($order)['total'], (string)$item->commercial_terms['total'], 2);
        if (bccomp($after, $this->summary($order)['base_received'], 2) < 0) throw ValidationException::withMessages(['item' => 'O cancelamento deixaria pagamentos acima do consumo. Estorne o excedente antes de cancelar.']);
    }

    public function settle(int $orderId, Sale $sale, array $input, int $actor): void
    {
        if (!DB::connection('tenant')->transactionLevel()) throw new \LogicException('O fechamento exige uma transação de venda.');
        app(CompanyOperations::class)->lock();
        app(OperationalModes::class)->require('restaurant');
        InstallerAccess::authorizedActor($actor, 'restaurant.checkout');
        $order = RestaurantOrder::lockForUpdate()->findOrFail($orderId);
        if ($sale->request_key !== Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant:'.$order->request_key)->toString()) throw new \LogicException('A venda não pertence à comanda.');
        $summary = $this->summary($order);
        $residual = $summary['balance'];
        if (bccomp($residual, '0', 2) < 0) throw ValidationException::withMessages(['payments' => 'Os recebimentos excedem o consumo. Confira o estorno antes de fechar.']);
        if (isset($input['payments'])) {
            $rows = $input['payments'];
        } elseif (bccomp($residual, '0', 2) > 0) {
            if (!isset($input['payment_option']) && !isset($input['payment_method'])) throw ValidationException::withMessages(['payments' => 'Informe a forma de pagamento do saldo restante.']);
            $choice = app(PaymentMethods::class)->resolve($input['payment_option'] ?? null, $input['payment_method'] ?? 'cash');
            $rows = [array_merge($input, ['payment_option' => $choice['id'], 'amount' => $residual, 'paid' => (bool)($input['paid'] ?? false)])];
        } else {
            $rows = [];
        }
        app(SalePayments::class)->recordWithPrepayments($sale, $rows, $actor, $order);
    }

    private function audit(string $action, int $actor, array $after): void
    {
        OperationRecord::create(['action' => $action, 'version' => 1, 'user_id' => $actor, 'before' => [], 'after' => $after, 'created_at' => now()]);
    }
}
