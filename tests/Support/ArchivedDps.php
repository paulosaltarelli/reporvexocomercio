<?php

namespace Tests\Support;

use App\Fiscal\Snapshot;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ArchivedDps
{
    /** Import public synthetic bytes from b954f2a, with local database references. */
    public static function import(Tenant $tenant, Sale $sale, int $actor, ?array $split = null): NfseDocument
    {
        $fixture = __DIR__.'/../Fixtures/nfse/archived';
        $snapshot = json_decode(file_get_contents($fixture.'.json'), true, flags: JSON_THROW_ON_ERROR);
        $xml = file_get_contents($fixture.'.xml');
        $snapshot += ['tenant_id' => $tenant->id, 'sale_id' => $sale->id, 'scope_confirmed_by' => $actor];
        if ($split !== null) {
            $snapshot['work_order_split'] = $split;
        }
        $configuration = NfseConfiguration::withoutEvents(fn () => NfseConfiguration::create([
            'cnpj' => $snapshot['issuer']['cnpj'], 'environment' => 'restricted', 'enabled' => true,
            'parameters' => $snapshot['configuration'], 'certificate_fingerprint' => $snapshot['certificate_fingerprint'],
            'updated_by' => $actor,
        ]));
        DB::connection('tenant')->table('nfse_sequences')->insert([
            'configuration_id' => $configuration->id, 'series' => 1, 'next_number' => 2,
        ]);
        return NfseDocument::withoutEvents(fn () => NfseDocument::create([
            'configuration_id' => $configuration->id, 'sale_id' => $sale->id, 'created_by' => $actor,
            'request_key' => (string) Str::uuid(), 'environment' => 'restricted', 'status' => 'draft_validated',
            'dps_id' => $snapshot['dps_id'], 'series' => $snapshot['series'], 'number' => $snapshot['number'],
            'competence' => $snapshot['competence'], 'total' => $snapshot['total'],
            'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot),
            'signed_xml' => $xml, 'xml_sha256' => hash('sha256', $xml), 'blocking_reason' => 'Documento histórico de teste.',
        ]));
    }
}
