<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalDocument;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class QueueNfe
{
    public function enqueue(Tenant $tenant, int $saleId, int $userId, array $operation): FiscalDocument
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }
        abort_unless(User::whereKey($userId)->where('active', true)->where('role', 'admin')->exists(), 403);

        return DB::connection('tenant')->transaction(function () use ($tenant, $saleId, $operation) {
            $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
            $cnpj = TaxpayerDocument::normalize((string) BusinessProfile::findOrFail(1)->document);
            $issuer = FiscalIssuer::where('document', $cnpj)->where('environment', 'homologation')->lockForUpdate()->first();
            if (! $issuer) {
                throw ValidationException::withMessages(['fiscal' => 'Configure o emitente e o certificado em Minha empresa antes de emitir.']);
            }
            $existing = FiscalDocument::where('sale_id', $saleId)->where('issuer_id', $issuer->id)->where('model', 55)->first();
            if ($existing) {
                return $existing;
            }
            if (FiscalDocument::where('sale_id', $saleId)->where(fn ($query) => $query->where('issuer_id', '<>', $issuer->id)->orWhere('model', '<>', 55))->whereNotIn('state', ['rejected', 'cancelled'])->exists()) {
                throw ValidationException::withMessages(['fiscal' => 'Esta venda já possui documento fiscal ativo de outro modelo ou emitente. Consulte ou reconcilie antes de emitir novamente.']);
            }
            if ($issuer->provider !== 'sefaz_direct') {
                throw ValidationException::withMessages(['fiscal' => 'Configure emissão direta SEFAZ para esta empresa.']);
            }
            $credential = FiscalCredential::where('issuer_id', $issuer->id)->lockForUpdate()->firstOrFail();
            $snapshot = app(NfePayload::class)->build($tenant, $sale, $issuer, $operation);
            $series = app(NfeCompanyConfiguration::class)->forIssuer($issuer)->configuration['series'];
            $sequences = DB::connection('tenant')->table('fiscal_sequences');
            $sequence = (clone $sequences)->where('issuer_id', $issuer->id)->where('model', 55)->where('series', $series)->lockForUpdate()->first();
            if (! $sequence || $sequence->next_number > 999999999) {
                throw ValidationException::withMessages(['fiscal' => 'Sequência indisponível ou esgotada.']);
            }
            $snapshot['payload']['numero'] = (string) $sequence->next_number;
            $snapshot['payload']['serie'] = (string) $series;
            $snapshot['tenant_id'] = $tenant->id;
            $snapshot['configuration'] = app(NfeCompanyConfiguration::class)->forIssuer($issuer)->configuration;
            $snapshot['model'] = 55;
            $prepared = app(NfeXmlBuilder::class)->prepare($snapshot['payload'], $snapshot['issuer_snapshot'], $snapshot['configuration'], $credential, (int) $sequence->next_number, (int) $series);
            $document = FiscalDocument::create(['issuer_id' => $issuer->id, 'sale_id' => $saleId, 'reference' => (string) Str::uuid(), 'model' => 55, 'series' => $series, 'number' => $sequence->next_number, 'state' => 'pending', 'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot), 'access_key' => $prepared['access_key'], 'signed_xml' => $prepared['xml'], 'signed_xml_sha256' => hash('sha256', $prepared['xml'])]);
            $sequences->where('id', $sequence->id)->update(['next_number' => $sequence->next_number + 1]);
            DB::connection('tenant')->table('fiscal_outbox')->insert(['tenant_id' => $tenant->id, 'document_id' => $document->id, 'operation_id' => (string) Str::uuid(), 'operation' => 'issue', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $document;
        }, 3);
    }

    public function cancel(Tenant $tenant, int $documentId, string $reason): void
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }
        DB::connection('tenant')->transaction(function () use ($tenant, $documentId, $reason) {
            $doc = FiscalDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $doc->model === 55, 404);
            if ($doc->state === 'cancelled') {
                return;
            }
            $issuer = FiscalIssuer::findOrFail($doc->issuer_id);
            $minutes = (int) (app(NfeCompanyConfiguration::class)->forIssuer($issuer)->configuration['cancellation_minutes'] ?? 0);
            if ($doc->state !== 'authorized' || ! $doc->authorized_at || $minutes <= 0 || $doc->authorized_at->addMinutes($minutes)->isPast()) {
                throw ValidationException::withMessages(['fiscal' => 'Cancelamento fora do estado/prazo configurado para a UF.']);
            }
            $outbox = DB::connection('tenant')->table('fiscal_outbox');
            if ((clone $outbox)->where('document_id', $documentId)->where('operation', 'cancel')->whereNull('completed_at')->exists()) {
                return;
            }
            $outbox->insert(['tenant_id' => $tenant->id, 'document_id' => $documentId, 'operation_id' => (string) Str::uuid(), 'operation' => 'cancel', 'parameters' => json_encode(['reason' => $reason]), 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    public function consult(Tenant $tenant, int $documentId): void
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }
        DB::connection('tenant')->transaction(function () use ($tenant, $documentId) {
            $doc = FiscalDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $doc->model === 55, 404);
            $outbox = DB::connection('tenant')->table('fiscal_outbox');
            if ((clone $outbox)->where('document_id', $documentId)->whereNull('completed_at')->exists()) {
                $outbox->where('document_id', $documentId)->whereNull('completed_at')->whereNull('lease_token')->update(['available_at' => now()]);

                return;
            }
            $outbox->insert(['tenant_id' => $tenant->id, 'document_id' => $documentId, 'operation_id' => (string) Str::uuid(), 'operation' => 'consult', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }

    public function resend(Tenant $tenant, int $documentId, int $userId): void
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }
        abort_unless(User::whereKey($userId)->where('active', true)->where('role', 'admin')->exists(), 403);
        DB::connection('tenant')->transaction(function () use ($documentId, $userId) {
            $doc = FiscalDocument::whereKey($documentId)->lockForUpdate()->firstOrFail();
            abort_unless((int) $doc->model === 55, 404);
            $table = DB::connection('tenant')->table('fiscal_outbox');
            $job = (clone $table)->where('document_id', $documentId)->where('operation', 'issue')->whereNull('completed_at')->lockForUpdate()->first();
            if ($doc->state !== 'uncertain' || ! $job || $job->last_error !== 'reference_not_found' || $job->lease_token) {
                throw ValidationException::withMessages(['fiscal' => 'Reenvio exige consulta que confirme referência não encontrada e nenhum processamento em andamento.']);
            }
            $table->where('id', $job->id)->update(['resend_requested_at' => now(), 'resend_requested_by' => $userId, 'available_at' => now()]);
            DB::connection('tenant')->table('fiscal_events')->insert(['document_id' => $documentId, 'event_reference' => (string) Str::uuid(), 'type' => 'same_reference_resend_requested', 'requested_by' => $userId, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);
    }
}
