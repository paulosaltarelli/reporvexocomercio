<?php

namespace App\Fiscal;

use App\Models\FiscalSimulation;
use App\Models\FiscalSimulationEvent;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Internal exercises only. Never signs, allocates a fiscal number or invokes a provider. */
final class SimulationFiscal
{
    public function create(Tenant $tenant, int $saleId, int $userId, string $requestKey, string $scenario = 'accepted'): FiscalSimulation
    {
        $this->context($tenant, $userId, $requestKey);
        if (! in_array($scenario, ['accepted', 'rejected', 'uncertain'], true)) {
            $this->invalid('scenario', 'Escolha um cenário de simulação válido.');
        }
        try {
            return DB::connection('tenant')->transaction(function () use ($tenant, $saleId, $userId, $requestKey, $scenario) {
                // Sale lock serializes creation for one sale without preventing a new intentional exercise.
                $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
                $existing = FiscalSimulation::where('request_key', $requestKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->sameCreation($existing, $tenant, $saleId, $userId, $scenario);
                }
                if (FiscalSimulationEvent::where('request_key', $requestKey)->exists()) {
                    $this->invalid('request_key', 'Chave já utilizada para outra operação simulada.');
                }
                if ($sale->status !== 'completed') {
                    $this->invalid('sale', 'Somente uma venda concluída pode originar simulação.');
                }
                $report = app(FiscalReadiness::class)->inspect($tenant, $sale);
                $snapshot = $report['snapshot'];
                $snapshot['schema'] = 'VendraSimulation';
                $snapshot['schema_version'] = 1;
                $snapshot['purpose'] = 'internal_simulation';
                $snapshot['environment'] = 'internal';
                unset($snapshot['candidate_model']);
                $snapshot['notice'] = 'SIMULAÇÃO INTERNA — SEM VALOR FISCAL';
                $snapshot['tenant_id'] = $tenant->id;
                $snapshot['captured_at'] = now()->toIso8601String();
                $snapshot['scenario'] = $scenario;
                $snapshot['readiness'] = ['ready_to_transmit' => false, 'blockers' => $report['blockers']];
                $simulation = FiscalSimulation::create([
                    'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'requested_by' => $userId,
                    'reference' => (string) Str::uuid(), 'request_key' => $requestKey,
                    'scenario' => $scenario, 'state' => $scenario,
                    'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot),
                ]);
                $this->event($simulation, $userId, $requestKey, 'create', null);
                return $simulation;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Read after rollback: a concurrent winner may be invisible to the old snapshot.
            $existing = FiscalSimulation::where('request_key', $requestKey)->first();
            if ($existing) {
                return $this->sameCreation($existing, $tenant, $saleId, $userId, $scenario);
            }
            if (FiscalSimulationEvent::where('request_key', $requestKey)->exists()) {
                $this->invalid('request_key', 'Chave já utilizada para outra operação simulada.');
            }
            throw $e;
        }
    }

    public function consult(Tenant $tenant, int $simulationId, int $userId, string $requestKey): FiscalSimulation
    {
        return $this->operate($tenant, $simulationId, $userId, $requestKey, 'consult');
    }

    public function cancel(Tenant $tenant, int $simulationId, int $userId, string $reason, string $requestKey): FiscalSimulation
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 15 || mb_strlen($reason) > 255) {
            $this->invalid('reason', 'Informe motivo entre 15 e 255 caracteres.');
        }
        return $this->operate($tenant, $simulationId, $userId, $requestKey, 'cancel', $reason);
    }

    private function operate(Tenant $tenant, int $id, int $userId, string $key, string $operation, ?string $reason = null): FiscalSimulation
    {
        $this->context($tenant, $userId, $key);
        try {
            return DB::connection('tenant')->transaction(function () use ($tenant, $id, $userId, $key, $operation, $reason) {
                $simulation = FiscalSimulation::whereKey($id)->lockForUpdate()->firstOrFail();
                $this->verify($tenant, $simulation);
                $existing = FiscalSimulationEvent::where('request_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    if ((int) $existing->simulation_id !== $id || (int) $existing->actor_id !== $userId || $existing->operation !== $operation || $existing->reason !== $reason) {
                        $this->invalid('request_key', 'Chave já utilizada para outra operação simulada.');
                    }
                    return $simulation;
                }
                if (FiscalSimulation::where('request_key', $key)->exists()) {
                    $this->invalid('request_key', 'Chave já utilizada para outra operação simulada.');
                }
                $before = $simulation->state;
                if ($operation === 'cancel') {
                    if ($before === 'cancelled') {
                        if ($simulation->cancellation_reason !== $reason) {
                            $this->invalid('reason', 'Simulação já cancelada com outro motivo.');
                        }
                        return $simulation;
                    }
                    if ($before !== 'accepted') {
                        $this->invalid('simulation', 'Somente uma simulação aceita pode ser cancelada.');
                    }
                    $simulation->state = 'cancelled';
                    $simulation->cancelled_at = now();
                    $simulation->cancellation_reason = $reason;
                } elseif ($before === 'uncertain') {
                    $simulation->state = 'accepted';
                    $simulation->consulted_at = now();
                } else {
                    // Repeated consultation is a read, not another fiscal/audit event.
                    return $simulation;
                }
                $simulation->save();
                $this->event($simulation, $userId, $key, $operation, $before, $reason);
                return $simulation;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $event = FiscalSimulationEvent::where('request_key', $key)->first();
            if ($event && (int) $event->simulation_id === $id && (int) $event->actor_id === $userId && $event->operation === $operation && $event->reason === $reason) {
                return $this->find($tenant, $id, $userId);
            }
            if ($event || FiscalSimulation::where('request_key', $key)->exists()) {
                $this->invalid('request_key', 'Chave já utilizada para outra operação simulada.');
            }
            throw $e;
        }
    }

    public function find(Tenant $tenant, int $id, int $userId): FiscalSimulation
    {
        $this->context($tenant, $userId);
        $simulation = FiscalSimulation::with('sale', 'requester', 'events.actor')->findOrFail($id);
        $this->verify($tenant, $simulation);
        return $simulation;
    }

    public function export(Tenant $tenant, int $id, int $userId): array
    {
        $simulation = $this->find($tenant, $id, $userId);
        return [
            'schema' => 'VendraSimulation', 'schema_version' => 1,
            'notice' => 'SIMULAÇÃO INTERNA — SEM VALOR FISCAL',
            'reference' => $simulation->reference, 'state' => $simulation->state,
            'snapshot_sha256' => $simulation->snapshot_sha256, 'snapshot' => $simulation->snapshot,
            'events' => $simulation->events->map(fn ($event) => $event->only(['operation', 'scenario', 'state_before', 'state_after', 'reason', 'created_at']))->all(),
        ];
    }

    public function authorize(Tenant $tenant, int $userId): void { $this->context($tenant, $userId); }

    private function sameCreation(FiscalSimulation $simulation, Tenant $tenant, int $saleId, int $userId, string $scenario): FiscalSimulation
    {
        $this->verify($tenant, $simulation);
        if ((int) $simulation->sale_id !== $saleId || (int) $simulation->requested_by !== $userId || $simulation->scenario !== $scenario) {
            $this->invalid('request_key', 'Chave já utilizada para outra simulação.');
        }
        return $simulation;
    }

    private function context(Tenant $tenant, int $userId, ?string $key = null): void
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) { abort(403); }
        abort_unless(User::whereKey($userId)->where('active', true)->where('role', 'admin')->exists(), 403);
        if ($key !== null && ! Str::isUuid($key)) { $this->invalid('request_key', 'Chave da operação inválida.'); }
    }

    private function verify(Tenant $tenant, FiscalSimulation $simulation): void
    {
        $snapshot = $simulation->snapshot;
        abort_unless((int) $simulation->tenant_id === (int) $tenant->id && is_array($snapshot) && (int) ($snapshot['tenant_id'] ?? 0) === (int) $tenant->id, 404);
        if ((int) ($snapshot['sale']['id'] ?? 0) !== (int) $simulation->sale_id || ($snapshot['scenario'] ?? '') !== $simulation->scenario || ! hash_equals($simulation->snapshot_sha256, Snapshot::hash($snapshot))) {
            $this->invalid('simulation', 'O retrato da simulação não passou na verificação de integridade.');
        }
    }

    private function event(FiscalSimulation $simulation, int $userId, string $key, string $operation, ?string $before, ?string $reason = null): void
    {
        FiscalSimulationEvent::create(['simulation_id' => $simulation->id, 'actor_id' => $userId, 'request_key' => $key, 'operation' => $operation, 'scenario' => $simulation->scenario, 'state_before' => $before, 'state_after' => $simulation->state, 'reason' => $reason]);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
