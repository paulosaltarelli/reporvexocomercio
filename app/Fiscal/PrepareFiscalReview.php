<?php

namespace App\Fiscal;

use App\Models\FiscalPreparation;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PrepareFiscalReview
{
    public function prepare(Tenant $tenant, int $saleId, int $userId, string $requestKey): FiscalPreparation
    {
        if (! Str::isUuid($requestKey)) {
            throw ValidationException::withMessages(['request_key' => 'Chave de conferência inválida.']);
        }
        // No tenant/model from client input; connection is resolved by middleware.
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            throw ValidationException::withMessages(['fiscal' => 'Contexto da empresa inválido.']);
        }
        abort_unless(User::where('active', true)->where('role', 'admin')->whereKey($userId)->exists(), 403);

        try {
            return DB::connection('tenant')->transaction(function () use ($tenant, $saleId, $userId, $requestKey) {
                $sale = Sale::lockForUpdate()->findOrFail($saleId);
                $existing = FiscalPreparation::where('request_key', $requestKey)->first();
                if ($existing) {
                    if ((int) $existing->sale_id !== $saleId || (int) $existing->tenant_id !== (int) $tenant->id) {
                        throw ValidationException::withMessages(['request_key' => 'Chave já utilizada para outra conferência.']);
                    }

                    return $existing;
                }
                if ($sale->status !== 'completed') {
                    throw ValidationException::withMessages(['sale' => 'Venda cancelada não pode ser preparada.']);
                }
                $report = app(FiscalReadiness::class)->inspect($tenant, $sale);
                $snapshot = $report['snapshot'];
                $snapshot['captured_at'] = now()->toIso8601String();

                return FiscalPreparation::create([
                    'tenant_id' => $tenant->id, 'sale_id' => $saleId, 'requested_by' => $userId, 'request_key' => $requestKey,
                    'state' => 'review_required', 'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot), 'blockers' => $report['blockers'],
                ]);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = FiscalPreparation::where('request_key', $requestKey)->first();
            if ($existing && (int) $existing->sale_id === $saleId && (int) $existing->tenant_id === (int) $tenant->id) {
                return $existing;
            }
            if ($existing) {
                throw ValidationException::withMessages(['request_key' => 'Chave já utilizada para outra conferência.']);
            }

            throw $exception;
        }
    }
}
