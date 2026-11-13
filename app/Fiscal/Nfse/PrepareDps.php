<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\CertificateInspector;
use App\Fiscal\Snapshot;
use App\Fiscal\TaxpayerDocument;
use App\Models\BusinessProfile;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class PrepareDps
{
    public function prepare(Tenant $tenant, int $saleId, int $actor, array $input): NfseDocument
    {
        Access::admin($tenant, $actor);
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'competence' => 'required|date_format:Y-m-d|before_or_equal:today', 'operation_scope_confirmed' => 'required|accepted'])->validate();
        return DB::connection('tenant')->transaction(function () use ($tenant, $saleId, $actor, $data) {
            $sale = Sale::whereKey($saleId)->lockForUpdate()->firstOrFail();
            $existing = NfseDocument::where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ((int) $existing->sale_id !== $saleId || $existing->competence->format('Y-m-d') !== $data['competence']) {
                    throw ValidationException::withMessages(['request_key' => 'Chave de preparação já utilizada com outra venda ou competência.']);
                }
                $this->verify($existing, $tenant, $actor);
                return $existing;
            }
            if ($existing = NfseDocument::where('sale_id', $saleId)->first()) {
                if ($existing->competence->format('Y-m-d') !== $data['competence']) {
                    throw ValidationException::withMessages(['competence' => 'Esta venda já tem DPS congelada com outra competência; a preparação existente foi preservada.']);
                }
                $this->verify($existing, $tenant, $actor);
                return $existing;
            }
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
            $cnpj = TaxpayerDocument::normalize((string) $profile->document);
            $configuration = NfseConfiguration::where('cnpj', $cnpj)->lockForUpdate()->first();
            $report = app(Readiness::class)->inspect($configuration, $sale);
            if (! $report['draft_ready']) {
                throw ValidationException::withMessages(['nfse' => $report['draft_blockers']]);
            }
            $parameters = $configuration->parameters;
            $sequenceQuery = DB::connection('tenant')->table('nfse_sequences')->where('configuration_id', $configuration->id)->where('series', $parameters['series']);
            $sequence = (clone $sequenceQuery)->lockForUpdate()->first();
            if (! $sequence || $sequence->next_number < 1 || $sequence->next_number > 999999999999999) {
                throw ValidationException::withMessages(['next_number' => 'Sequência DPS ausente ou esgotada.']);
            }
            $number = (string) $sequence->next_number;
            $series = (int) $parameters['series'];
            $issued = now($parameters['timezone']);
            if ($data['competence'] > $issued->format('Y-m-d')) {
                throw ValidationException::withMessages(['competence' => 'Competência não pode ser posterior à emissão no fuso do prestador.']);
            }
            $secrets = $configuration->secrets ?? [];
            $pfx = base64_decode($secrets['pfx'] ?? '', true) ?: '';
            app(CertificateInspector::class)->inspect($pfx, $secrets['certificate_password'] ?? '', $cnpj);
            $line = $sale->items->first();
            $snapshot = [
                'environment' => 'restricted', 'tenant_id' => $tenant->id, 'sale_id' => $saleId,
                'dps_id' => 'DPS'.$parameters['municipality_code'].'2'.$cnpj.str_pad((string) $series, 5, '0', STR_PAD_LEFT).str_pad($number, 15, '0', STR_PAD_LEFT),
                'series' => $series, 'number' => $number, 'issued_at' => $issued->format('Y-m-d\TH:i:sP'),
                'competence' => $data['competence'], 'total' => Decimal::input((string) $sale->total, 2, 'total', '0.01'),
                'configuration' => $parameters,
                'issuer' => ['cnpj' => $cnpj, 'legal_name' => $profile->legal_name, 'municipality_code' => $profile->municipality_code],
                'service' => ['item_id' => $line->id, 'product_id' => $line->product_id, 'description' => $line->description, 'quantity' => (string) $line->quantity, 'unit_price' => (string) $line->unit_price, 'total' => (string) $line->total],
                'customer' => $sale->customer ? ['document' => TaxpayerDocument::normalize((string) $sale->customer->document), 'name' => $sale->customer->name] : null,
                'scope_confirmed_by' => $actor, 'national_issuer_confirmed' => true,
                'certificate_fingerprint' => $configuration->certificate_fingerprint,
                'limitations' => ['draft_only', 'no_external_acceptance', 'federal_rtc_not_transmitted', 'local_service_no_withholding'],
            ];
            try {
                $xml = app(DpsBuilder::class)->build($snapshot, $pfx, $secrets['certificate_password'] ?? '');
            } catch (\App\Fiscal\FiscalTransportException) {
                throw ValidationException::withMessages(['nfse' => 'DPS não passou pela assinatura/validação do schema oficial v1.01. Confira os dados; nenhuma transmissão ocorreu.']);
            }
            $document = NfseDocument::create([
                'configuration_id' => $configuration->id, 'sale_id' => $saleId, 'created_by' => $actor,
                'request_key' => $data['request_key'], 'environment' => 'restricted', 'status' => 'draft_validated',
                'dps_id' => $snapshot['dps_id'], 'series' => $series, 'number' => $number, 'competence' => $data['competence'],
                'total' => $snapshot['total'], 'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot),
                'signed_xml' => $xml, 'xml_sha256' => hash('sha256', $xml), 'blocking_reason' => Readiness::TRANSPORT_BLOCKER,
            ]);
            $sequenceQuery->update(['next_number' => (int) $number + 1]);
            return $document;
        }, 3);
    }

    public function verify(NfseDocument $document, Tenant $tenant, int $actor): void
    {
        Access::admin($tenant, $actor);
        $snapshot = $document->snapshot;
        $configuration = NfseConfiguration::find($document->configuration_id);
        abort_unless($document->environment === 'restricted' && $document->status === 'draft_validated'
            && hash_equals($document->snapshot_sha256, Snapshot::hash($snapshot))
            && hash_equals($document->xml_sha256, hash('sha256', $document->signed_xml)), 409, 'Integridade da DPS inválida.');
        abort_unless((int) ($snapshot['tenant_id'] ?? 0) === (int) $tenant->id
            && (int) ($snapshot['sale_id'] ?? 0) === (int) $document->sale_id
            && ($snapshot['environment'] ?? '') === $document->environment
            && ($snapshot['dps_id'] ?? '') === $document->dps_id
            && (string) ($snapshot['series'] ?? '') === (string) $document->series
            && (string) ($snapshot['number'] ?? '') === (string) $document->number
            && ($snapshot['competence'] ?? '') === $document->competence->format('Y-m-d')
            && Decimal::input((string) ($snapshot['total'] ?? ''), 2, 'total', '0.01') === Decimal::input((string) $document->total, 2, 'total', '0.01')
            && $configuration && ($snapshot['issuer']['cnpj'] ?? '') === $configuration->cnpj, 409, 'A DPS não corresponde ao contexto da empresa e da venda.');
        try {
            app(DpsBuilder::class)->validate($document->signed_xml, $snapshot);
        } catch (\App\Fiscal\FiscalTransportException) {
            abort(409, 'Assinatura ou conteúdo da DPS não corresponde ao registro congelado.');
        }
    }
}
