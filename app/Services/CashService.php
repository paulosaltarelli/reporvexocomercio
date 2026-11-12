<?php

namespace App\Services;

use App\Models\CashClosure;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashService
{
    /** Live overview for an open day, or the immutable snapshot once closed. */
    public function summary(string $date, string $opening = '0.00'): array
    {
        $date = FinancialService::date($date, 'date')->toDateString();
        $opening = Decimal::input($opening, 2, 'opening_balance');
        $closure = CashClosure::where('date', $date)->first();
        if ($closure) {
            return array_merge($closure->snapshot, ['is_closed' => true, 'closure_id' => $closure->id]);
        }

        return $this->ledgerSummary($date, $opening, false);
    }

    /**
     * Must run inside the transaction that inserts Payment. Parent/entry locks
     * precede calendar/day locks; no Payment row is locked before this gate.
     */
    public function lockOpenDay(string $date, ?string $replayKey = null): ?Payment
    {
        $date = FinancialService::date($date, 'paid_on')->toDateString();
        $db = DB::connection('tenant');
        if ($db->transactionLevel() === 0) {
            throw new \LogicException('O bloqueio do dia exige uma transação financeira ativa.');
        }
        $calendar = $db->table('cash_closure_locks')->where('id', 1)->sharedLock()->first();
        if (! $calendar) {
            throw new \LogicException('Execute a migração de caixa antes de registrar pagamentos.');
        }
        // A locking current read is necessary when a surrounding transaction
        // already established an older REPEATABLE READ snapshot.
        $last = CashClosure::orderByDesc('date')->sharedLock()->first();
        $db->table('cash_days')->insertOrIgnore(['date' => $date]);
        $day = $db->table('cash_days')->where('date', $date)->lockForUpdate()->first();
        // Immutable existing payments may be replayed after closing. This current
        // read happens AFTER calendar/day locks, avoiding stale snapshots and lock inversion.
        if ($replayKey !== null) {
            $existing = Payment::where('request_key', $replayKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
        }
        if ($day->closed_at !== null || ($last && $date <= $last->date->toDateString())) {
            throw ValidationException::withMessages(['paid_on' => 'Este dia financeiro já foi consolidado. Registre a correção identificada em uma data aberta posterior ao último fechamento.']);
        }

        return null;
    }

    public function close(array $data, int $userId): CashClosure
    {
        $key = (string) ($data['request_key'] ?? '');
        if (! Str::isUuid($key)) {
            throw ValidationException::withMessages(['request_key' => 'Chave do fechamento inválida.']);
        }
        $date = FinancialService::date((string) $data['date'], 'date');
        if ($date->toDateString() > now()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Não é possível fechar uma data futura.']);
        }
        $date = $date->toDateString();
        $opening = Decimal::input((string) $data['opening_balance'], 2, 'opening_balance');
        $counted = Decimal::input((string) $data['counted_balance'], 2, 'counted_balance');
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : null;
        $notes = $notes === '' ? null : $notes;
        if ($notes !== null && mb_strlen($notes) > 2000) {
            throw ValidationException::withMessages(['notes' => 'As observações devem ter até 2000 caracteres.']);
        }

        return DB::connection('tenant')->transaction(function () use ($key, $date, $opening, $counted, $notes, $userId) {
            $db = DB::connection('tenant');
            // Exclusive calendar lock serializes closings and waits for all
            // payment writers; closings never need sale or financial-entry locks.
            $calendar = $db->table('cash_closure_locks')->where('id', 1)->lockForUpdate()->first();
            if (! $calendar) {
                throw new \LogicException('Execute a migração de caixa antes de fechar o dia.');
            }
            $existing = CashClosure::where('request_key', $key)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->date->toDateString() !== $date || (int) $existing->user_id !== $userId
                    || bccomp($existing->opening_balance, $opening, 2) !== 0
                    || bccomp($existing->counted_balance, $counted, 2) !== 0
                    || $existing->notes !== $notes) {
                    throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outro fechamento.']);
                }

                return $existing;
            }
            $db->table('cash_days')->insertOrIgnore(['date' => $date]);
            $day = $db->table('cash_days')->where('date', $date)->lockForUpdate()->first();
            $last = CashClosure::orderByDesc('date')->lockForUpdate()->first();
            if ($day->closed_at !== null || ($last && $date <= $last->date->toDateString())) {
                throw ValidationException::withMessages(['date' => 'O fechamento é imutável e deve seguir a ordem das datas, após o último dia fechado.']);
            }
            if ($last && bccomp($opening, $last->counted_balance, 2) !== 0) {
                throw ValidationException::withMessages(['opening_balance' => 'A abertura deve ser igual ao valor contado no último fechamento: R$ '.$last->counted_balance.'.']);
            }
            $earlier = Payment::where('paid_on', '<', $date)
                ->when($last, fn ($query) => $query->where('paid_on', '>', $last->date->toDateString()))
                ->orderBy('paid_on')->orderBy('id')->lockForUpdate()->first();
            if ($earlier) {
                throw ValidationException::withMessages(['date' => 'Feche primeiro o dia com movimento financeiro mais antigo ainda aberto: '.$earlier->paid_on->format('d/m/Y').'.']);
            }
            $snapshot = $this->ledgerSummary($date, $opening, true);
            unset($snapshot['is_closed'], $snapshot['closure_id']);
            $difference = bcsub($counted, $snapshot['expected_balance'], 2);
            foreach (['cash_in' => $snapshot['cash_in'], 'cash_out' => $snapshot['cash_out'], 'expected_balance' => $snapshot['expected_balance'], 'difference' => $difference] as $field => $value) {
                if (bccomp($value, '-999999999999.99', 2) < 0 || bccomp($value, '999999999999.99', 2) > 0) {
                    throw ValidationException::withMessages([$field => 'O total ultrapassa o limite monetário suportado para o fechamento.']);
                }
            }
            $closedAt = now();
            $closure = CashClosure::create([
                'request_key' => $key, 'date' => $date, 'user_id' => $userId,
                'opening_balance' => $opening, 'cash_in' => $snapshot['cash_in'], 'cash_out' => $snapshot['cash_out'],
                'expected_balance' => $snapshot['expected_balance'], 'counted_balance' => $counted,
                'difference' => $difference, 'notes' => $notes, 'snapshot' => $snapshot, 'closed_at' => $closedAt,
            ]);
            $db->table('cash_days')->where('date', $date)->update(['closed_at' => $closedAt]);

            return $closure;
        }, 3);
    }

    private function ledgerSummary(string $date, string $opening, bool $currentRead): array
    {
        $query = Payment::where('paid_on', $date)->orderBy('id');
        if ($currentRead) {
            $query->lockForUpdate();
        }
        $payments = $query->get();
        $totals = [];
        foreach (['cash', 'pix', 'credit', 'debit', 'transfer', 'invoice'] as $method) {
            $totals[$method] = ['in' => '0.00', 'out' => '0.00', 'net' => '0.00'];
        }
        foreach ($payments as $payment) {
            if (! in_array($payment->direction, ['in', 'out'], true)) {
                throw new \LogicException('O histórico financeiro contém uma direção inválida.');
            }
            $totals[$payment->method] ??= ['in' => '0.00', 'out' => '0.00', 'net' => '0.00'];
            $totals[$payment->method][$payment->direction] = bcadd($totals[$payment->method][$payment->direction], $payment->amount, 2);
        }
        foreach ($totals as &$total) {
            $total['net'] = bcsub($total['in'], $total['out'], 2);
        }
        unset($total);

        return [
            'date' => $date, 'opening_balance' => $opening,
            'cash_in' => $totals['cash']['in'], 'cash_out' => $totals['cash']['out'],
            'expected_balance' => bcadd($opening, $totals['cash']['net'], 2),
            'totals_by_method' => $totals, 'payment_count' => $payments->count(),
            'is_closed' => false, 'closure_id' => null,
        ];
    }
}
