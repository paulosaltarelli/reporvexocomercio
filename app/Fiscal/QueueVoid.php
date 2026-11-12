<?php

namespace App\Fiscal;

use App\Models\FiscalIssuer;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QueueVoid
{
    public function enqueue(Tenant $tenant, FiscalIssuer $issuer, int $userId, int $last, string $reason, string $key): int
    {
        if ($issuer->environment !== 'homologation' || ! Str::isUuid($key) || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }

        return DB::connection('tenant')->transaction(function () use ($tenant, $issuer, $userId, $last, $reason, $key) {
            $seq = DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 65)->where('series', $issuer->validated_configuration['series'])->lockForUpdate()->firstOrFail();
            $voids = DB::connection('tenant')->table('fiscal_voids');
            $existing = (clone $voids)->where('operation_id', $key)->first();
            if ($existing) {
                if ((int) $existing->issuer_id !== $issuer->id || (int) $existing->last_number !== $last || $existing->reason !== $reason) {
                    throw ValidationException::withMessages(['request_key' => 'Chave usada por outra inutilização.']);
                }

                return $existing->id;
            }
            if ($last < $seq->next_number || $last > 999999999 || $last - $seq->next_number > 1000) {
                throw ValidationException::withMessages(['last_number' => 'Informe faixa de até 1001 números a partir da próxima numeração disponível.']);
            }
            $id = $voids->insertGetId(['issuer_id' => $issuer->id, 'operation_id' => $key, 'model' => 65, 'series' => $seq->series, 'year' => (int) now()->setTimezone($issuer->validated_configuration['timezone'])->format('Y'), 'first_number' => $seq->next_number, 'last_number' => $last, 'reason' => $reason, 'state' => 'pending', 'requested_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
            DB::connection('tenant')->table('fiscal_sequences')->where('id', $seq->id)->update(['next_number' => $last + 1]);
            DB::connection('tenant')->table('fiscal_outbox')->insert(['tenant_id' => $tenant->id, 'void_id' => $id, 'document_id' => null, 'operation_id' => $key, 'operation' => 'invalidate', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        }, 3);
    }
}
