<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\FiscalTransportException;
use App\Fiscal\Snapshot;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Tenant;
use App\Services\Decimal;

final class HistoricalDps
{
    public function verify(NfseDocument $document, Tenant $tenant, int $actor): void
    {
        Access::admin($tenant, $actor);
        $snapshot = $document->snapshot;
        $issuer = NfseConfiguration::whereKey($document->configuration_id)->value('cnpj');
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
            && $issuer && ($snapshot['issuer']['cnpj'] ?? '') === $issuer, 409, 'A DPS não corresponde ao contexto da empresa e da venda.');
        try {
            app(DpsVerifier::class)->validate($document->signed_xml, $snapshot);
        } catch (FiscalTransportException) {
            abort(409, 'Assinatura ou conteúdo da DPS não corresponde ao registro congelado.');
        }
    }
}
