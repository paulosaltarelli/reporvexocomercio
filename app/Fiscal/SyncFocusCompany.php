<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;

final class SyncFocusCompany
{
    public function sync(FiscalIssuer $issuer): void
    {
        if ($issuer->environment !== 'homologation') {
            throw new FiscalTransportException('production_disabled');
        }
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $s = $credential->secrets;
        $expectedHash = app(CompanyFiscalConfiguration::class)->hash($issuer);
        $version = $credential->updated_at->toISOString();
        $token = (string) ($s['management_token'] ?? '');
        $path = '/empresas/'.rawurlencode($credential->provider_company_id);
        $before = app(FocusClient::class)->request('GET', $path, $token, [], true);
        if (TaxpayerDocument::normalize((string) ($before['cnpj'] ?? '')) !== $issuer->document) {
            throw new FiscalTransportException('provider_company_mismatch');
        }
        $p = BusinessProfile::findOrFail(1);
        $c = $issuer->validated_configuration;
        $data = ['nome' => $p->legal_name, 'nome_fantasia' => $p->trade_name, 'cnpj' => $issuer->document, 'inscricao_estadual' => $p->state_registration, 'regime_tributario' => $c['crt'], 'logradouro' => $p->street, 'numero' => $p->number, 'bairro' => $p->district, 'municipio' => $p->city, 'uf' => $p->state, 'cep' => str_replace('-', '', (string) $p->postal_code), 'arquivo_certificado_base64' => $s['pfx'], 'senha_certificado' => $s['certificate_password'], 'certificado_especifico' => true, 'enviar_email_homologacao' => false];
        if (! empty($s['csc'])) {
            $data['csc_nfce_homologacao'] = $s['csc'];
            $data['id_token_nfce_homologacao'] = $s['csc_id'] ?? '';
        }
        $result = app(FocusClient::class)->request('PUT', $path, $token, $data, true);
        if (TaxpayerDocument::normalize((string) ($result['cnpj'] ?? '')) !== $issuer->document) {
            throw new FiscalTransportException('provider_company_mismatch');
        }
        // Check current config again; a concurrent local change must require another sync.
        $credential->refresh();
        if ($credential->updated_at->toISOString() !== $version || ! hash_equals($expectedHash, app(CompanyFiscalConfiguration::class)->hash($issuer->fresh()))) {
            throw new FiscalTransportException('configuration_changed_during_sync');
        }
        $credential->synced_hash = $expectedHash;
        $credential->save();
    }
}
