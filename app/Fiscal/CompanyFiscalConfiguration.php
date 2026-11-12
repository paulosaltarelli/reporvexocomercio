<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyFiscalConfiguration
{
    public function save(Tenant $tenant, array $configuration, #[\SensitiveParameter] array $input, #[\SensitiveParameter] ?string $pfx): FiscalIssuer
    {
        if (DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            abort(403);
        }
        $cnpj = TaxpayerDocument::normalize((string) BusinessProfile::findOrFail(1)->document);
        if (! TaxpayerDocument::cnpj($cnpj)) {
            throw ValidationException::withMessages(['document' => 'Complete o CNPJ válido em Minha empresa.']);
        }

        return DB::connection('tenant')->transaction(function () use ($configuration, $input, $pfx, $cnpj) {
            $profile = BusinessProfile::whereKey(1)->lockForUpdate()->firstOrFail();
            if (empty($configuration['timezone']) || ! in_array($configuration['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                throw ValidationException::withMessages(['timezone' => 'Informe o fuso horário fiscal do emitente.']);
            }
            $allowed = ['mei' => ['4'], 'simples' => ['1', '2'], 'presumido' => ['3'], 'real' => ['3']];
            if (! in_array((string) $configuration['crt'], $allowed[$profile->tax_regime] ?? [], true)) {
                throw ValidationException::withMessages(['crt' => 'CRT incompatível com o regime tributário cadastrado. Confirme os dois campos com o contador.']);
            }
            $issuer = FiscalIssuer::firstOrCreate(['document' => $cnpj, 'environment' => 'homologation'], ['provider' => 'focus', 'enabled' => false]);
            $credential = FiscalCredential::where('issuer_id', $issuer->id)->first();
            $secrets = $credential?->secrets ?? [];
            $metadata = [];
            foreach (['emission_token', 'management_token', 'csc', 'csc_id'] as $field) {
                if (isset($input[$field]) && $input[$field] !== '') {
                    $secrets[$field] = $input[$field];
                }
            }
            if ($pfx !== null) {
                $metadata = app(CertificateInspector::class)->inspect($pfx, (string) ($input['certificate_password'] ?? ''), $cnpj);
                $secrets['pfx'] = base64_encode($pfx);
                $secrets['certificate_password'] = (string) ($input['certificate_password'] ?? '');
            } elseif (! $credential) {
                throw ValidationException::withMessages(['certificate' => 'Envie o certificado A1/PFX da empresa.']);
            }
            foreach (['emission_token', 'management_token'] as $required) {
                if (empty($secrets[$required])) {
                    throw ValidationException::withMessages([$required => 'Informe a credencial do provedor para esta empresa.']);
                }
            }
            if ($configuration['csc_required'] && (empty($secrets['csc']) || empty($secrets['csc_id']))) {
                throw ValidationException::withMessages(['csc' => 'Informe CSC e identificador de homologação conforme a UF.']);
            }
            $issuer->update(['validated_configuration' => $configuration, 'enabled' => true]);
            $credential ??= new FiscalCredential(['issuer_id' => $issuer->id]);
            $credential->fill($metadata);
            $credential->secrets = $secrets;
            $credential->provider_company_id = $configuration['provider_company_id'];
            $credential->synced_hash = null;
            $credential->save();
            $sequence = DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->where('model', 65)->where('series', $configuration['series'])->lockForUpdate()->first();
            if (! $sequence) {
                DB::connection('tenant')->table('fiscal_sequences')->insert(['issuer_id' => $issuer->id, 'model' => 65, 'series' => $configuration['series'], 'next_number' => $configuration['next_number']]);
            } elseif ((int) $sequence->next_number !== (int) $configuration['next_number']) {
                throw ValidationException::withMessages(['next_number' => 'Sequência já existe; informe a próxima numeração atual. Redefinição não é permitida.']);
            }

            return $issuer;
        }, 3);
    }

    public function hash(FiscalIssuer $issuer): string
    {
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();

        return Snapshot::hash(['issuer' => $issuer->document, 'configuration' => $issuer->validated_configuration, 'profile' => BusinessProfile::findOrFail(1)->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code', 'email', 'phone']), 'certificate' => $credential->certificate_fingerprint, 'credential_digest' => Snapshot::hash($credential->secrets)]);
    }
}
