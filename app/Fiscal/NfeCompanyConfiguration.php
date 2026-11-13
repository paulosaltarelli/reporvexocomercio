<?php
namespace App\Fiscal;
use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\FiscalModelConfiguration;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** NF-e settings/sequence are independent of NFC-e; only the company A1 is shared. */
final class NfeCompanyConfiguration
{
    public function save(Tenant $tenant, array $configuration, #[\SensitiveParameter] array $input, #[\SensitiveParameter] ?string $pfx): FiscalModelConfiguration
    {
        $this->context($tenant);
        $configuration = Validator::make($configuration, [
            'series' => 'required|integer|min:0|max:889', 'next_number' => 'required|integer|min:1|max:999999999',
            'timezone' => 'required|timezone', 'crt' => 'required|in:1,2,3,4', 'natureza_operacao' => 'required|string|max:60',
            'cancellation_minutes' => 'required|integer|min:0|max:30', 'uf_validation_reference' => 'required|string|max:180',
        ])->validate();
        return DB::connection('tenant')->transaction(function () use ($configuration, $input, $pfx) {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
            $document = TaxpayerDocument::normalize((string) $profile->document);
            if (! preg_match('/^[0-9]{14}$/D', $document) || ! TaxpayerDocument::cnpj($document)) { $this->fail('document', 'Informe o CNPJ válido da empresa antes de configurar NF-e.'); }
            $this->crt($profile->tax_regime, (string) $configuration['crt']);
            $issuer = FiscalIssuer::firstOrCreate(['document' => $document, 'environment' => 'homologation'], ['provider' => 'sefaz_direct', 'enabled' => false]);
            if ($issuer->provider !== 'sefaz_direct') { $this->fail('fiscal', 'Este fluxo atende somente emissão SEFAZ direta.'); }
            $credential = FiscalCredential::where('issuer_id', $issuer->id)->lockForUpdate()->first();
            $secrets = $credential?->secrets ?? [];
            if ($pfx !== null) {
                $metadata = app(CertificateInspector::class)->inspect($pfx, (string) ($input['certificate_password'] ?? ''), $document);
                $credential ??= new FiscalCredential(['issuer_id' => $issuer->id]);
                $credential->fill($metadata);
                $secrets['pfx'] = base64_encode($pfx); $secrets['certificate_password'] = (string) ($input['certificate_password'] ?? '');
                $credential->secrets = $secrets;
                // A changed A1 must be revalidated for NFC-e too. CSC itself remains untouched.
                $credential->validated_hash = null; $credential->synced_hash = null;
                $credential->save();
            } elseif (! $credential) { $this->fail('certificate', 'Envie o certificado A1/PFX da empresa. NF-e não exige CSC.'); }
            $row = FiscalModelConfiguration::updateOrCreate(['issuer_id' => $issuer->id, 'model' => 55], ['enabled' => true, 'configuration' => $configuration, 'validated_hash' => null]);
            $table = DB::connection('tenant')->table('fiscal_sequences');
            $sequence = (clone $table)->where('issuer_id', $issuer->id)->where('model', 55)->where('series', $configuration['series'])->lockForUpdate()->first();
            if (! $sequence) { $table->insert(['issuer_id' => $issuer->id, 'model' => 55, 'series' => $configuration['series'], 'next_number' => $configuration['next_number']]); }
            elseif ((int) $sequence->next_number !== (int) $configuration['next_number']) { $this->fail('next_number', 'A sequência NF-e já existe. Informe a próxima numeração atual; redefinição é bloqueada.'); }
            return $row;
        }, 3);
    }

    public function validateLocal(Tenant $tenant): FiscalModelConfiguration
    {
        $this->context($tenant);
        return DB::connection('tenant')->transaction(function () {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
            $issuer = FiscalIssuer::where('document', TaxpayerDocument::normalize((string) $profile->document))->where('environment', 'homologation')->lockForUpdate()->firstOrFail();
            $row = FiscalModelConfiguration::where('issuer_id', $issuer->id)->where('model', 55)->lockForUpdate()->firstOrFail();
            if (! $row->enabled || $issuer->provider !== 'sefaz_direct') { $this->fail('fiscal', 'Configure a NF-e direta de homologação antes de validar.'); }
            $this->crt($profile->tax_regime, (string) ($row->configuration['crt'] ?? ''));
            $credential = FiscalCredential::where('issuer_id', $issuer->id)->lockForUpdate()->firstOrFail();
            $secret = $credential->secrets; $pfx = base64_decode((string) ($secret['pfx'] ?? ''), true);
            if (! is_string($pfx) || $pfx === '') { $this->fail('certificate', 'Cadastre o certificado A1/PFX da empresa.'); }
            $credential->fill(app(CertificateInspector::class)->inspect($pfx, (string) ($secret['certificate_password'] ?? ''), $issuer->document)); $credential->save();
            $row->validated_hash = $this->hash($issuer); $row->save();
            return $row;
        }, 3);
    }

    public function forIssuer(FiscalIssuer $issuer): FiscalModelConfiguration
    {
        return FiscalModelConfiguration::where('issuer_id', $issuer->id)->where('model', 55)->firstOrFail();
    }

    public function hash(FiscalIssuer $issuer): string
    {
        $row = $this->forIssuer($issuer); $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail(); $secret = $credential->secrets;
        return Snapshot::hash(['model' => 55, 'issuer' => $issuer->document, 'configuration' => $row->configuration,
            'profile' => BusinessProfile::findOrFail(1)->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code']),
            'certificate' => $credential->certificate_fingerprint, 'credential_digest' => Snapshot::hash(array_intersect_key($secret, array_flip(['pfx', 'certificate_password'])))]);
    }
    public function crt(?string $regime, string $crt): void
    {
        $allowed = ['mei' => ['4'], 'simples' => ['1', '2'], 'presumido' => ['3'], 'real' => ['3']];
        if (! in_array($crt, $allowed[$regime] ?? [], true)) { $this->fail('crt', 'Confira o regime tributário da empresa e o CRT da NF-e com o contador.'); }
    }
    private function context(Tenant $tenant): void { abort_unless($tenant->active && DB::connection('tenant')->getDatabaseName() === $tenant->db_name, 403); }
    private function fail(string $key, string $message): never { throw ValidationException::withMessages([$key => $message]); }
}
