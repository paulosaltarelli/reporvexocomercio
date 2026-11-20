<?php

namespace App\Platform;

use App\Models\Tenant;
use App\Services\Decimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractBilling
{
    public function create(int $tenantId, array $input, int $actorId, int $authVersion): Contract
    {
        $terms = $this->terms($input);

        return DB::connection('central')->transaction(function () use ($tenantId, $terms, $actorId, $authVersion) {
            $actor = $this->actor($actorId, $authVersion);
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $this->preventOverlap($tenantId, $terms);
            $contract = Contract::create(['tenant_id' => $tenantId] + $terms);
            $this->synchronize($contract);
            $this->audit($contract, $actor, 'contract.created', ['terms' => $this->snapshot($contract)]);

            return $contract;
        }, 3);
    }

    public function update(int $tenantId, int $contractId, array $input, int $actorId, int $authVersion): Contract
    {
        $expected = Validator::make($input, ['expected_version' => 'required|integer|min:1'])->validate()['expected_version'];
        $terms = $this->terms($input);

        return DB::connection('central')->transaction(function () use ($tenantId, $contractId, $expected, $terms, $actorId, $authVersion) {
            $actor = $this->actor($actorId, $authVersion);
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $contract = $this->lockedContract($tenantId, $contractId);
            if ($contract->version !== (int) $expected) {
                throw ValidationException::withMessages(['expected_version' => 'O contrato mudou. Atualize a página e confira as condições.']);
            }
            $this->preventOverlap($tenantId, $terms, $contractId);
            $before = $this->snapshot($contract);
            $contract->fill($terms);
            if (! $contract->isDirty()) {
                return $contract;
            }
            $contract->version++;
            $contract->save();
            [, $changes] = $this->synchronize($contract);
            $this->audit($contract, $actor, 'contract.updated', ['before' => $before, 'after' => $this->snapshot($contract), 'installments' => $changes]);

            return $contract;
        }, 3);
    }

    /** Rebuild missing periods from the current terms, including after a retry. */
    public function generate(int $tenantId, int $contractId): int
    {
        return DB::connection('central')->transaction(function () use ($tenantId, $contractId) {
            $contract = $this->lockedContract($tenantId, $contractId);
            [$created] = $this->synchronize($contract);

            return $created;
        }, 3);
    }

    /**
     * The same transaction must also persist the local charge before attempting
     * the bank request. Locking here freezes the exact terms presented to the bank.
     */
    public function lockForCharge(int $tenantId, int $installmentId): ContractInstallment
    {
        if (DB::connection('central')->transactionLevel() === 0) {
            throw new \LogicException('Registre a intenção de cobrança dentro da transação central.');
        }
        $installment = $this->lockedInstallment($tenantId, $installmentId);
        if ($installment->cancelled_at || bccomp($installment->balance, '0', 2) <= 0) {
            throw ValidationException::withMessages(['installment' => 'A parcela precisa estar em aberto para emitir a cobrança.']);
        }
        if (! $installment->payment_locked_at) {
            $installment->update(['payment_locked_at' => now()]);
        }

        return $installment;
    }

    /** Called only after the payment provider validates its local charge and receipt. */
    public function confirmPix(int $tenantId, int $installmentId, string $txid, string $e2e, string $amount): ContractInstallment
    {
        $amount = Decimal::input($amount, 2, 'amount', '0.01');
        if (! preg_match('/^[a-zA-Z0-9]{26,35}$/D', $txid) || ! preg_match('/^E[a-zA-Z0-9]{31}$/D', $e2e)) {
            throw ValidationException::withMessages(['payment' => 'Identificação Pix inválida.']);
        }
        try {
            return DB::connection('central')->transaction(function () use ($tenantId, $installmentId, $txid, $e2e, $amount) {
                $installment = $this->lockedInstallment($tenantId, $installmentId);
                $prior = ContractPayment::where('txid', $txid)->orWhere('e2e', $e2e)->lockForUpdate()->first();
                if ($prior) {
                    if ((int) $prior->tenant_id !== $tenantId || (int) $prior->installment_id !== $installmentId
                        || $prior->txid !== $txid || $prior->e2e !== $e2e || bccomp($prior->amount, $amount, 2) !== 0) {
                        throw ValidationException::withMessages(['payment' => 'Identificação bancária vinculada a outro recebimento.']);
                    }

                    return $installment;
                }
                if (! $installment->payment_locked_at || $installment->cancelled_at || bccomp($amount, $installment->balance, 2) > 0) {
                    throw ValidationException::withMessages(['payment' => 'O recebimento exige uma cobrança emitida e valor dentro do saldo disponível.']);
                }
                ContractPayment::create(['tenant_id' => $tenantId, 'installment_id' => $installmentId, 'txid' => $txid, 'e2e' => $e2e, 'amount' => $amount]);
                $installment->update(['paid_amount' => bcadd($installment->paid_amount, $amount, 2)]);

                return $installment;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Different installments tried to claim the same globally unique bank receipt.
            // Identical replays on one installment were already serialized by its row lock.
            throw ValidationException::withMessages(['payment' => 'Este recebimento Pix já foi vinculado.']);
        }
    }

    private function terms(array $input): array
    {
        $rules = ['start_date' => 'required|date_format:Y-m-d|after_or_equal:1000-01-01',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'due_day' => 'required|integer|between:1,31',
            'monthly_amount' => 'required|numeric', 'restricted_modules' => 'sometimes|array'];
        if (array_key_exists('restricted_modules', $input)) {
            $rules['restricted_modules.*'] = ['required', 'string', 'distinct', Rule::in(array_keys(ContractAccess::MODULES))];
        }
        $terms = Validator::make($input, $rules)->validate();
        $terms['monthly_amount'] = Decimal::input((string) $terms['monthly_amount'], 2, 'monthly_amount', '0.01');
        if (isset($terms['restricted_modules'])) {
            sort($terms['restricted_modules'], SORT_STRING);
        }

        return $terms;
    }

    private function preventOverlap(int $tenantId, array $terms, ?int $except = null): void
    {
        // All writers hold the tenant row, including when it has no contracts yet.
        $query = Contract::where('tenant_id', $tenantId)->where('start_date', '<=', $terms['end_date'])
            ->where('end_date', '>=', $terms['start_date']);
        if ($except !== null) {
            $query->where('id', '<>', $except);
        }
        if ($query->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['start_date' => 'Já existe contrato desta empresa com vigência nesse período.']);
        }
    }

    private function lockedContract(int $tenantId, int $contractId): Contract
    {
        return Contract::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($contractId);
    }

    private function lockedInstallment(int $tenantId, int $installmentId): ContractInstallment
    {
        return ContractInstallment::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($installmentId);
    }

    private function actor(int $actorId, int $authVersion): PlatformAdmin
    {
        $actor = PlatformAdmin::whereKey($actorId)->sharedLock()->first();
        abort_unless($actor && $actor->active && $actor->auth_version === $authVersion, 403);

        return $actor;
    }

    private function snapshot(Contract $contract): array
    {
        return ['start_date' => $contract->start_date->toDateString(), 'end_date' => $contract->end_date->toDateString(),
            'due_day' => $contract->due_day, 'monthly_amount' => $contract->monthly_amount,
            'restricted_modules' => $contract->restricted_modules ?? [], 'version' => $contract->version];
    }

    private function audit(Contract $contract, PlatformAdmin $actor, string $action, array $changes): void
    {
        PlatformAudit::create(['platform_admin_id' => $actor->id, 'tenant_id' => $contract->tenant_id, 'actor_name' => $actor->name,
            'action' => $action, 'changes' => ['contract_id' => $contract->id] + $changes]);
    }

    /** Generate one due date per calendar month that lands inside the inclusive term. */
    private function synchronize(Contract $contract): array
    {
        $existing = ContractInstallment::where('tenant_id', $contract->tenant_id)->where('contract_id', $contract->id)
            ->orderBy('period')->lockForUpdate()->get()->keyBy(fn ($row) => $row->period->toDateString());
        $today = now()->toDateString();
        $created = 0;
        $changes = [];
        $newRows = [];
        $timestamp = now();
        $start = $contract->start_date;
        $end = $contract->end_date;
        for ($month = $start->startOfMonth(); $month <= $end; $month = $month->addMonthNoOverflow()) {
            $due = $month->day(min($contract->due_day, $month->daysInMonth));
            if ($due < $start || $due > $end) {
                continue;
            }
            $period = $month->toDateString();
            $dueDate = $due->toDateString();
            $row = $existing->pull($period);
            if (! $row) {
                $newRows[] = ['tenant_id' => $contract->tenant_id, 'contract_id' => $contract->id, 'period' => $period,
                    'due_date' => $dueDate, 'amount' => $contract->monthly_amount, 'paid_amount' => '0.00', 'created_at' => $timestamp, 'updated_at' => $timestamp];
                if (count($newRows) === 250) {
                    ContractInstallment::insert($newRows);
                    $newRows = [];
                }
                $created++;

                continue;
            }
            if ($this->frozen($row, $today)) {
                $this->assertFrozenInsideTerm($row, $contract);

                continue;
            }
            if (! $row->cancelled_at && $row->due_date->toDateString() > $today && $dueDate < $today) {
                continue;
            }
            $before = $this->lineSnapshot($row);
            $row->fill(['due_date' => $dueDate, 'amount' => $contract->monthly_amount, 'cancelled_at' => null]);
            if ($row->isDirty()) {
                $row->save();
                $changes[] = ['id' => $row->id, 'before' => $before, 'after' => $this->lineSnapshot($row)];
            }
        }
        if ($newRows) {
            ContractInstallment::insert($newRows);
        }
        foreach ($existing as $row) {
            if ($row->cancelled_at) {
                continue;
            }
            if ($this->frozen($row, $today)) {
                // A day-of-month edit can remove a boundary period's nominal date, while
                // its historical installment is still inside the contract's inclusive term.
                $this->assertFrozenInsideTerm($row, $contract);

                continue;
            }
            $before = $this->lineSnapshot($row);
            $row->update(['cancelled_at' => now()]);
            $changes[] = ['id' => $row->id, 'before' => $before, 'after' => $this->lineSnapshot($row)];
        }

        return [$created, $changes];
    }

    private function assertFrozenInsideTerm(ContractInstallment $row, Contract $contract): void
    {
        if ($row->due_date < $contract->start_date || $row->due_date > $contract->end_date) {
            throw ValidationException::withMessages(['end_date' => 'A nova vigência excluiria parcela vencida, recebida ou com cobrança emitida. Concilie o histórico antes de alterar.']);
        }
    }

    private function frozen(ContractInstallment $row, string $today): bool
    {
        return ! $row->cancelled_at && ($row->payment_locked_at || bccomp($row->paid_amount,'0',2) > 0
            || $row->due_date->toDateString() < $today);
    }

    private function lineSnapshot(ContractInstallment $row): array
    {
        return ['due_date' => $row->due_date->toDateString(), 'amount' => $row->amount, 'cancelled' => (bool) $row->cancelled_at];
    }
}
