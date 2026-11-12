<?php

namespace App\Fiscal;

use App\Models\DemoRun;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\FiscalIssuer;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use NFePHP\DA\NFe\Danfce;

final class ProcessFiscalOutbox
{
    public function run(int $tenantId, int $outboxId): void
    {
        $connection = app(TenantConnection::class);
        try {
            $tenant = Tenant::where('active', true)->findOrFail($tenantId);
            $connection->connect($tenant);
            $claim = DB::connection('tenant')->transaction(function () use ($tenantId, $outboxId) {
                $table = DB::connection('tenant')->table('fiscal_outbox');
                $job = (clone $table)->where('id', $outboxId)->where('tenant_id', $tenantId)->whereNull('completed_at')->where('available_at', '<=', now())->where(fn ($q) => $q->whereNull('leased_until')->orWhere('leased_until', '<', now()))->lockForUpdate()->first();
                if (! $job) {
                    return null;
                }
                $token = (string) Str::uuid();
                $table->where('id', $job->id)->update(['lease_token' => $token, 'leased_until' => now()->addMinutes(5), 'attempts' => $job->attempts + 1, 'updated_at' => now(), 'resend_requested_at' => null]);
                if ($job->operation === 'invalidate' && $job->void_id) {
                    return [$job, null, $token, false];
                }
                $doc = FiscalDocument::whereKey($job->document_id)->lockForUpdate()->firstOrFail();
                if ((int) ($doc->snapshot['tenant_id'] ?? 0) !== $tenantId || ! hash_equals($doc->snapshot_sha256, Snapshot::hash($doc->snapshot))) {
                    throw new FiscalTransportException('snapshot_integrity_invalid');
                }
                if (! is_string($doc->signed_xml) || $doc->signed_xml === '' || ! $doc->signed_xml_sha256 || ! hash_equals($doc->signed_xml_sha256, hash('sha256', $doc->signed_xml)) || ! preg_match('/^[0-9]{44}$/D', (string) $doc->access_key) || ! isset($doc->snapshot['configuration'])) {
                    throw new FiscalTransportException('signed_xml_integrity_invalid');
                }
                $send = $job->operation === 'issue' && $doc->state === 'pending' && $job->attempts === 0;
                if ($send) {
                    $doc->update(['state' => 'sent']);
                }

                return [$job, $doc, $token, $send];
            });
            if (! $claim) {
                return;
            }
            [$job,$doc,$token,$send] = $claim;
            if ($doc === null) {
                $this->void($job, $token);

                return;
            }
            try {
                $issuer = FiscalIssuer::findOrFail($doc->issuer_id);
                app(ProductionGate::class)->assertAllowed(Environment::from($issuer->environment), $tenant->fiscal_demonstration || DemoRun::whereNotNull('completed_at')->exists(), $tenant->fiscal_production_approved_at !== null);
                if ($issuer->environment !== 'homologation' || $issuer->provider !== 'sefaz_direct') {
                    throw new FiscalTransportException('production_disabled');
                }
                $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
                $payload = $doc->snapshot['payload'];
                $payload['_access_key'] = $doc->access_key;
                $payload['_certificate_fingerprint'] = $doc->snapshot['certificate_fingerprint'];
                $payload['_schema_package'] = $doc->snapshot['schema_package'];
                $payload['_signed_xml'] = $doc->signed_xml;
                $payload['_issuer_snapshot'] = $doc->snapshot['issuer_snapshot'];
                $payload['_configuration'] = $doc->snapshot['configuration'];
                $payload['_protocol'] = $doc->protocol;
                $provider = new SefazProvider($issuer, $credential, $payload);
                if ($send) {
                    if ($credential->valid_from->isFuture() || $credential->valid_until->isPast()) {
                        throw new FiscalTransportException('certificate_expired');
                    }
                    if (! $issuer->enabled || ! $credential->validated_hash || ! hash_equals($credential->validated_hash, app(CompanyFiscalConfiguration::class)->hash($issuer)) || ! hash_equals($doc->snapshot['configuration_hash'], app(CompanyFiscalConfiguration::class)->hash($issuer))) {
                        throw new FiscalTransportException('issuer_configuration_changed');
                    }
                    if (Sale::whereKey($doc->sale_id)->value('status') !== 'completed') {
                        throw new FiscalTransportException('commercial_sale_cancelled');
                    }
                    $result = $provider->issue($doc->reference, $payload);
                } else {
                    try {
                        $result = $provider->consult($doc->reference);
                    } catch (FiscalTransportException $e) {
                        if ($e->getMessage() !== 'reference_not_found' || ! $job->resend_requested_at || $job->operation !== 'issue') {
                            throw $e;
                        }
                        if ($credential->valid_from->isFuture() || $credential->valid_until->isPast() || ! $issuer->enabled || ! $credential->validated_hash || ! hash_equals($credential->validated_hash, app(CompanyFiscalConfiguration::class)->hash($issuer)) || ! hash_equals($doc->snapshot['configuration_hash'], app(CompanyFiscalConfiguration::class)->hash($issuer))) {
                            throw new FiscalTransportException('issuer_configuration_changed');
                        }
                        // Explicitly requested replay after a fresh SEFAZ 217.
                        // Exactly the same signed XML/key/number, never a regenerated invoice.
                        if (Sale::whereKey($doc->sale_id)->value('status') !== 'completed') {
                            throw new FiscalTransportException('commercial_sale_cancelled');
                        }
                        $result = $provider->issue($doc->reference, $payload);
                    }
                    if ($job->operation === 'cancel' && $result->state === DocumentState::Authorized) {
                        $params = json_decode($job->parameters, true, flags: JSON_THROW_ON_ERROR);
                        $result = $provider->cancel($doc->reference, $params['reason'], $job->operation_id);
                    }
                }
                // Generate DANFE from verified authorized XML, outside any DB transaction.
                $pdf = null;
                if ($result->state === DocumentState::Authorized && $result->xml) {
                    $pdf = (new Danfce($result->xml))->render();
                }
                if ($result->state === DocumentState::Cancelled) {
                    $xml = app(PrivateFiscalFiles::class)->get($tenantId, $doc->id, 'xml');
                    $danfe = new Danfce($xml);
                    $danfe->setAsCanceled();
                    $pdf = $danfe->render();
                }
                DB::connection('tenant')->transaction(function () use ($tenantId, $job, $doc, $token, $result, $pdf) {
                    $table = DB::connection('tenant')->table('fiscal_outbox');
                    $current = (clone $table)->where('id', $job->id)->lockForUpdate()->first();
                    if (! $current || $current->lease_token !== $token) {
                        return;
                    }
                    $locked = FiscalDocument::whereKey($doc->id)->lockForUpdate()->firstOrFail();
                    $next = $result->state;
                    if (DocumentState::from($locked->state)->canTransitionTo($next)) {
                        if ($result->xml) {
                            app(PrivateFiscalFiles::class)->put($tenantId, $doc->id, $next === DocumentState::Cancelled ? 'cancel_xml' : 'xml', $result->xml);
                        }
                        if ($pdf) {
                            app(PrivateFiscalFiles::class)->put($tenantId, $doc->id, 'danfe', $pdf);
                        }
                        $values = ['state' => $next->value, 'official_code' => $result->officialCode, 'operator_message' => null];
                        if ($next === DocumentState::Authorized) {
                            $values += ['access_key' => $result->accessKey, 'protocol' => $result->protocol, 'authorized_at' => $locked->authorized_at ?? Carbon::parse($result->authorizedAt)->setTimezone(config('app.timezone'))];
                        }
                        if ($next === DocumentState::Cancelled) {
                            $values['cancelled_at'] = now();
                        }
                        $locked->update($values);
                        DB::connection('tenant')->table('fiscal_events')->updateOrInsert(['event_reference' => $job->operation_id.':'.$next->value], ['document_id' => $doc->id, 'type' => $next->value, 'official_code' => $result->officialCode, 'protocol' => $result->protocol, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                    }
                    $terminal = in_array($next, [DocumentState::Authorized, DocumentState::Rejected, DocumentState::Cancelled], true) && ! ($job->operation === 'cancel' && $next !== DocumentState::Cancelled);
                    $table->where('id', $job->id)->update(['completed_at' => $terminal ? now() : null, 'available_at' => now()->addMinutes(2), 'leased_until' => null, 'lease_token' => null, 'last_error' => null]);
                });
            } catch (\Throwable $e) {
                $error = $e instanceof FiscalTransportException ? $e->getMessage() : 'fiscal_processing_failed';
                DB::connection('tenant')->transaction(function () use ($doc, $outboxId, $token, $error) {
                    $table = DB::connection('tenant')->table('fiscal_outbox');
                    $job = (clone $table)->where('id', $outboxId)->lockForUpdate()->first();
                    if (! $job || $job->lease_token !== $token) {
                        return;
                    }
                    if ($job->operation === 'cancel' && preg_match('/^cancellation_rejected_([0-9]{3})$/D', $error, $match)) {
                        FiscalDocument::whereKey($doc->id)->where('state', 'authorized')->update(['operator_message' => 'Cancelamento rejeitado pela SEFAZ. Código: '.$match[1].'. A nota permanece autorizada.']);
                        DB::connection('tenant')->table('fiscal_events')->updateOrInsert(['event_reference' => $job->operation_id.':cancel_rejected'], ['document_id' => $doc->id, 'type' => 'cancellation_rejected', 'official_code' => $match[1], 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                        $table->where('id', $outboxId)->update(['completed_at' => now(), 'leased_until' => null, 'lease_token' => null, 'last_error' => $error, 'updated_at' => now()]);
                        return;
                    }
                    FiscalDocument::whereKey($doc->id)->whereIn('state', ['sent', 'uncertain'])->update(['state' => 'uncertain', 'operator_message' => 'Consulta necessária. Código técnico: '.$error]);
                    $table->where('id', $outboxId)->update(['available_at' => now()->addMinutes(min(60, max(2, $job->attempts * 2))), 'leased_until' => null, 'lease_token' => null, 'last_error' => $error]);
                });
            }
        } finally {
            $connection->disconnect();
        }
    }

    private function void(object $job, string $token): void
    {
        // Inutilization is deliberately unavailable in this direct adapter release.
        // A historical queued operation must never fall through to another provider.
        DB::connection('tenant')->transaction(function () use ($job, $token) {
            $table = DB::connection('tenant')->table('fiscal_outbox');
            $current = (clone $table)->where('id', $job->id)->lockForUpdate()->first();
            if (! $current || $current->lease_token !== $token) {
                return;
            }
            $table->where('id', $job->id)->update(['completed_at' => now(), 'leased_until' => null, 'lease_token' => null, 'last_error' => 'direct_invalidation_not_enabled', 'updated_at' => now()]);
            DB::connection('tenant')->table('fiscal_voids')->where('id', $job->void_id)->whereIn('state', ['pending', 'uncertain'])->update(['state' => 'uncertain', 'updated_at' => now()]);
        });
    }
}
