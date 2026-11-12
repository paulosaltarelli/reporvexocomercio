<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class FinancialService
{
    public static function date(string $value, string $field): CarbonImmutable
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new \InvalidArgumentException;
            }

            return $date;
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Informe uma data válida no formato AAAA-MM-DD.']);
        }
    }

    public static function paymentMethod(string $method): string
    {
        if (! in_array($method, ['cash', 'pix', 'credit', 'debit', 'transfer', 'invoice'], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Forma de pagamento inválida.']);
        }

        return $method;
    }

    private function uuid(string $key): string
    {
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['request_key' => 'Chave da operação inválida.']);
        }

        return $key;
    }

    public function schedule(array $data, int $userId): Collection
    {
        $key = $this->uuid((string) ($data['request_key'] ?? ''));
        $amount = Decimal::input((string) $data['amount'], 2, 'amount', '0.01');
        $firstDate = self::date((string) $data['due_date'], 'due_date');
        $count = filter_var($data['installments'] ?? 1, FILTER_VALIDATE_INT);
        $cents = bcmul($amount, '100', 0);
        if ($count === false || $count < 1 || $count > 24 || bccomp($cents, (string) $count, 0) < 0) {
            throw ValidationException::withMessages(['installments' => 'Informe de 1 a 24 parcelas, cada uma com pelo menos um centavo.']);
        }
        if (! in_array($data['direction'], ['in', 'out'], true) || (! empty($data['sale_id']) && ! empty($data['purchase_id']))) {
            throw ValidationException::withMessages(['direction' => 'Origem ou tipo da conta inválido.']);
        }
        $description = (string) $data['description'];
        $category = (string) $data['category'];
        if (trim($description) === '' || mb_strlen($description) > 180 || trim($category) === '' || mb_strlen($category) > 100) {
            throw ValidationException::withMessages(['description' => 'Informe descrição e categoria dentro dos limites.']);
        }

        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId, $key, $amount, $firstDate, $count, $cents, $description, $category) {
                $sale = ! empty($data['sale_id']) ? Sale::lockForUpdate()->findOrFail($data['sale_id']) : null;
                $purchase = ! empty($data['purchase_id']) ? Purchase::lockForUpdate()->findOrFail($data['purchase_id']) : null;
                if (($sale && ($sale->status !== 'completed' || $data['direction'] !== 'in' || bccomp($sale->total, $amount, 2) !== 0))
                    || ($purchase && ($data['direction'] !== 'out' || bccomp($purchase->total, $amount, 2) !== 0))) {
                    throw ValidationException::withMessages(['amount' => 'A conta deve corresponder ao valor e à origem da operação.']);
                }
                $existing = FinancialEntry::where('schedule_key', $key)->lockForUpdate()->orderBy('installment_no')->get();
                if ($existing->isNotEmpty()) {
                    return $this->validateScheduleReplay($existing, $data, $userId, $amount, $count, $firstDate);
                }
                if (($sale && $sale->entries()->exists()) || ($purchase && $purchase->entries()->exists())) {
                    throw ValidationException::withMessages(['request_key' => 'A operação já possui um plano de parcelas.']);
                }
                $baseCents = bcdiv($cents, (string) $count, 0);
                $entries = new Collection;
                for ($i = 1; $i <= $count; $i++) {
                    $partCents = $i === $count ? bcsub($cents, bcmul($baseCents, (string) ($count - 1), 0), 0) : $baseCents;
                    $entries->push(FinancialEntry::create([
                        'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:installment:'.$key.':'.$i)->toString(),
                        'schedule_key' => $key, 'installment_no' => $i, 'installments_count' => $count,
                        'user_id' => $userId, 'supplier_id' => $data['supplier_id'] ?? null,
                        'sale_id' => $sale?->id, 'purchase_id' => $purchase?->id,
                        'direction' => $data['direction'],
                        'description' => $count > 1 ? mb_substr($description, 0, 150).' ('.$i.'/'.$count.')' : $description,
                        'category' => $category, 'amount' => bcdiv($partCents, '100', 2),
                        'due_date' => $firstDate->addMonthsNoOverflow($i - 1)->toDateString(),
                        'paid_amount' => '0.00', 'legacy_paid_amount' => '0.00', 'legacy_import' => false,
                    ]));
                }

                return $entries;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = FinancialEntry::where('schedule_key', $key)->lockForUpdate()->orderBy('installment_no')->get();
            if ($existing->isNotEmpty()) {
                return $this->validateScheduleReplay($existing, $data, $userId, $amount, $count, $firstDate);
            }

            throw $exception;
        }
    }

    private function validateScheduleReplay(Collection $entries, array $data, int $userId, string $total, int $count, CarbonImmutable $date): Collection
    {
        $sum = '0.00';
        foreach ($entries as $entry) {
            $sum = bcadd($sum, $entry->amount, 2);
            if ((int) $entry->user_id !== $userId || $entry->direction !== $data['direction']
                || (int) ($entry->sale_id ?? 0) !== (int) ($data['sale_id'] ?? 0)
                || (int) ($entry->purchase_id ?? 0) !== (int) ($data['purchase_id'] ?? 0)
                || (int) ($entry->supplier_id ?? 0) !== (int) ($data['supplier_id'] ?? 0)
                || $entry->description !== ($count > 1 ? mb_substr($data['description'], 0, 150).' ('.$entry->installment_no.'/'.$count.')' : $data['description'])
                || (int) $entry->installments_count !== $count
                || $entry->category !== $data['category']
                || $entry->due_date->toDateString() !== $date->addMonthsNoOverflow($entry->installment_no - 1)->toDateString()) {
                throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outro plano de parcelas.']);
            }
        }
        if ($entries->count() !== $count || bccomp($sum, $total, 2) !== 0) {
            throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outro plano de parcelas.']);
        }

        return $entries;
    }

    public function pay(int $entryId, string $amount, string $paidOn, string $method, int $userId, string $key): Payment
    {
        $key = $this->uuid($key);
        $amount = Decimal::input($amount, 2, 'amount', '0.01');
        $paidOn = self::date($paidOn, 'paid_on')->toDateString();
        $method = self::paymentMethod($method);

        try {
            return DB::connection('tenant')->transaction(function () use ($entryId, $amount, $paidOn, $method, $userId, $key) {
                // Lock parent first, matching cancellation and all other payment paths.
                $origin = FinancialEntry::findOrFail($entryId);
                $sale = $origin->sale_id ? Sale::lockForUpdate()->findOrFail($origin->sale_id) : null;
                $purchase = $origin->purchase_id ? Purchase::lockForUpdate()->findOrFail($origin->purchase_id) : null;
                $entry = FinancialEntry::lockForUpdate()->findOrFail($entryId);
                $existing = Payment::where('request_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    return $this->validatePaymentReplay($existing, $entryId, $amount, $paidOn, $method, $userId);
                }
                if ($entry->cancelled_at || ($sale && $sale->status !== 'completed')) {
                    throw ValidationException::withMessages(['amount' => 'Não é possível baixar uma conta cancelada.']);
                }
                $balance = bcsub($entry->amount, $entry->paid_amount, 2);
                if (bccomp($amount, $balance, 2) > 0) {
                    throw ValidationException::withMessages(['amount' => 'O pagamento não pode ultrapassar o saldo da conta.']);
                }
                $payment = Payment::create([
                    'request_key' => $key, 'entry_id' => $entry->id, 'user_id' => $userId,
                    'amount' => $amount, 'paid_on' => $paidOn, 'method' => $method,
                    'direction' => $entry->direction, 'kind' => 'payment',
                ]);
                $paid = bcadd($entry->paid_amount, $amount, 2);
                $entry->update(['paid_amount' => $paid, 'paid_at' => bccomp($paid, $entry->amount, 2) === 0 ? now() : null]);
                if ($sale) {
                    $this->syncParent($sale, 'sale_id');
                }
                if ($purchase) {
                    $this->syncParent($purchase, 'purchase_id');
                }

                return $payment;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Payment::where('request_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $this->validatePaymentReplay($existing, $entryId, $amount, $paidOn, $method, $userId);
            }

            throw $exception;
        }
    }

    private function validatePaymentReplay(Payment $payment, int $entryId, string $amount, string $date, string $method, int $userId): Payment
    {
        if ((int) $payment->entry_id !== $entryId || (int) $payment->user_id !== $userId
            || $payment->kind !== 'payment' || bccomp($payment->amount, $amount, 2) !== 0
            || $payment->paid_on->toDateString() !== $date || $payment->method !== $method) {
            throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outro pagamento.']);
        }

        return $payment;
    }

    private function syncParent(Sale|Purchase $parent, string $foreignKey): void
    {
        $entries = FinancialEntry::where($foreignKey, $parent->id)->whereNull('cancelled_at')->lockForUpdate()->get();
        $sum = '0.00';
        $allPaid = true;
        foreach ($entries as $entry) {
            $sum = bcadd($sum, $entry->amount, 2);
            $allPaid = $allPaid && bccomp($entry->paid_amount, $entry->amount, 2) === 0;
        }
        $parent->update(['paid' => $allPaid && bccomp($sum, $parent->total, 2) === 0]);
    }

    /** Cancel outstanding obligations and record confirmed manual refunds without deleting history. */
    public function cancelSale(int $saleId, int $userId, bool $refundConfirmed): void
    {
        DB::connection('tenant')->transaction(function () use ($saleId, $userId, $refundConfirmed) {
            $sale = Sale::lockForUpdate()->findOrFail($saleId);
            $entries = FinancialEntry::where('sale_id', $sale->id)->orderBy('id')->lockForUpdate()->get();
            $payments = Payment::whereIn('entry_id', $entries->modelKeys())->where('kind', 'payment')
                ->whereDoesntHave('reversal')->orderBy('id')->lockForUpdate()->get();
            $received = '0.00';
            foreach ($entries as $entry) {
                if (bccomp($entry->legacy_paid_amount, '0', 2) > 0) {
                    throw ValidationException::withMessages(['refund_confirmed' => 'Recebimento legado sem data e comprovante de baixa. Faça a reconciliação antes de cancelar.']);
                }
                $ledger = '0.00';
                foreach ($payments->where('entry_id', $entry->id) as $payment) {
                    $ledger = bcadd($ledger, $payment->amount, 2);
                }
                if (bccomp($ledger, $entry->paid_amount, 2) !== 0) {
                    throw ValidationException::withMessages(['refund_confirmed' => 'O histórico de recebimentos precisa ser reconciliado antes do cancelamento.']);
                }
                $received = bcadd($received, $ledger, 2);
            }
            if (($entries->isEmpty() && $sale->paid && bccomp($sale->total, '0', 2) > 0)) {
                throw ValidationException::withMessages(['refund_confirmed' => 'Venda legada sem histórico financeiro. Faça a reconciliação antes de cancelar.']);
            }
            if (bccomp($received, '0', 2) > 0 && ! $refundConfirmed) {
                throw ValidationException::withMessages(['refund_confirmed' => 'Confirme que o reembolso foi realizado antes de cancelar esta venda.']);
            }
            foreach ($payments as $payment) {
                Payment::create([
                    'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:refund:'.$payment->request_key)->toString(),
                    'entry_id' => $payment->entry_id, 'user_id' => $userId, 'amount' => $payment->amount,
                    'paid_on' => now()->toDateString(), 'method' => $payment->method,
                    'direction' => $payment->direction === 'in' ? 'out' : 'in',
                    'kind' => 'refund', 'reversal_of' => $payment->id,
                ]);
            }
            foreach ($entries as $entry) {
                $entry->update(['paid_amount' => '0.00', 'paid_at' => null, 'cancelled_at' => now()]);
            }
            $sale->update(['paid' => false]);
        }, 3);
    }
}
