<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\FiscalItemRule;
use App\Models\FiscalSetting;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

final class NfcePayload
{
    public function build(Tenant $tenant, Sale $sale, FiscalIssuer $issuer, array $operation): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['fiscal' => $message]);
        if ($issuer->environment !== 'homologation') {
            $fail('Produção fiscal não habilitada.');
        }
        $profile = BusinessProfile::findOrFail(1);
        $c = $issuer->validated_configuration;
        $report = app(FiscalReadiness::class)->inspect($tenant, $sale);
        foreach ($report['blockers'] as $blocker) {
            if (str_starts_with($blocker['code'], 'issuer.')) {
                $fail($blocker['message']);
            }
        }
        if (! $issuer->enabled || TaxpayerDocument::normalize((string) $profile->document) !== $issuer->document) {
            $fail('Configuração do emitente indisponível ou CNPJ alterado.');
        }
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        if ($credential->valid_from->isFuture() || $credential->valid_until->isPast() || ! $credential->synced_hash || ! hash_equals($credential->synced_hash, app(CompanyFiscalConfiguration::class)->hash($issuer))) {
            $fail('Certificado/configuração precisa de validação e sincronização com o provedor.');
        }
        if (! FiscalSetting::find(1)?->nfce_accredited || empty($c['uf_validation_reference'])) {
            $fail('Confirme credenciamento e adequação da operação à UF com o contador.');
        }
        foreach (['legal_name', 'state_registration', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code', 'tax_regime'] as $field) {
            if (trim((string) $profile->$field) === '') {
                $fail('Complete o cadastro do emitente: '.$field.'.');
            }
        }
        if (($operation['destination_state'] ?? '') !== $profile->state || ! ($operation['consumer_final'] ?? false) || ! ($operation['not_taxpayer'] ?? false) || ($operation['presence'] ?? '') !== '1') {
            $fail('Este fluxo atende venda presencial interna a consumidor final não contribuinte. Operação diferente exige configuração/documento específico.');
        }
        if ($sale->status !== 'completed') {
            $fail('Venda cancelada não pode ser emitida.');
        }
        $sale->load('items', 'customer');
        $items = [];
        $rules = [];
        $goods = '0.00';
        $goodsGross = '0.00';
        $goodsDiscount = '0.00';
        $services = '0.00';
        foreach ($sale->items->sortBy('id') as $line) {
            if ($line->product_id && $line->service_id) {
                $fail('Item com vínculos ambíguos: revisar o histórico antes da emissão.');
            }
            if ($line->fiscal_kind === 'service' || $line->service_id) {
                $services = bcadd($services, $line->total, 2);

                continue;
            }
            if ($line->fiscal_kind !== 'product') {
                $fail('Venda antiga sem classificação fiscal congelada. Faça revisão assistida antes de emitir.');
            }
            $product = Product::findOrFail($line->product_id);
            $rule = FiscalItemRule::where('product_id', $product->id)->first();
            if (! $rule || ! $rule->accountant_reference) {
                $fail('Produto '.$product->name.': falta regra fiscal validada pelo contador.');
            }
            if (! preg_match('/^[0-9]{8}$/D', (string) $product->ncm) || ! preg_match('/^[0-8]$/D', (string) $product->origin) || ! $product->unit) {
                $fail('Produto '.$product->name.': confira NCM, origem e unidade.');
            }
            $tax = app(TaxProfile::class)->fields($rule->parameters, $line->total, (string) $c['crt']);
            if (bccomp(bcsub($line->gross_total, $line->discount_amount, 2), $line->total, 2) !== 0) {
                $fail('Bruto, desconto e líquido divergem do histórico do item.');
            }
            $rules[$product->id] = ['parameters' => $rule->parameters, 'accountant_reference' => $rule->accountant_reference, 'validated_at' => $rule->updated_at->toIso8601String()];
            $items[] = array_merge($tax, ['numero_item' => (string) (count($items) + 1), 'codigo_ncm' => $product->ncm, 'codigo_produto' => $product->sku, 'descricao' => $line->description, 'quantidade_comercial' => $line->quantity, 'quantidade_tributavel' => $line->quantity, 'valor_unitario_comercial' => $line->unit_price, 'valor_unitario_tributavel' => $line->unit_price, 'valor_bruto' => $line->gross_total, 'valor_desconto' => $line->discount_amount, 'unidade_comercial' => $product->unit, 'unidade_tributavel' => $product->unit, 'icms_origem' => $product->origin]);
            $goods = bcadd($goods, $line->total, 2);
            $goodsGross = bcadd($goodsGross, $line->gross_total, 2);
            $goodsDiscount = bcadd($goodsDiscount, $line->discount_amount, 2);
        }
        if (! $items || bccomp($goods, '0', 2) <= 0) {
            $fail('Não há mercadorias para NFC-e. Serviços exigem NFS-e separada.');
        }
        if (bccomp(bcadd($goods, $services, 2), $sale->total, 2) !== 0) {
            $fail('Total comercial não corresponde aos itens.');
        }
        if (! $sale->paid || ! in_array($sale->payment_method, ['cash', 'pix', 'transfer'], true)) {
            $fail('Este fluxo requer venda recebida em dinheiro, Pix ou transferência. Cartão/a prazo exige dados fiscais de pagamento adicionais.');
        }
        $payload = ['cnpj_emitente' => $issuer->document, 'data_emissao' => now()->setTimezone($c['timezone'])->toIso8601String(), 'presenca_comprador' => '1', 'modalidade_frete' => '9', 'local_destino' => '1', 'natureza_operacao' => $c['natureza_operacao'], 'indicador_inscricao_estadual_destinatario' => '9', 'items' => $items, 'formas_pagamento' => [['forma_pagamento' => ['cash' => '01', 'pix' => '17', 'transfer' => '18'][$sale->payment_method], 'valor_pagamento' => $goods]], 'valor_total' => $goods, 'valor_produtos' => $goodsGross, 'valor_desconto' => $goodsDiscount];
        // Required legal homologation label; business/issuer data are never fixtures.
        $payload['items'][0]['descricao'] = 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        $document = TaxpayerDocument::normalize((string) $sale->customer?->document);
        if ($document !== '') {
            if (TaxpayerDocument::cpf($document)) {
                $payload['cpf_destinatario'] = $document;
            } elseif (TaxpayerDocument::cnpj($document)) {
                $payload['cnpj_destinatario'] = $document;
            } else {
                $fail('CPF/CNPJ do destinatário inválido.');
            }
            $payload['nome_destinatario'] = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        } elseif (bccomp($goods, (string) $c['identification_limit'], 2) >= 0) {
            $fail('Identificação do consumidor exigida pelo limite configurado para a UF.');
        }

        return ['payload' => $payload, 'issuer_snapshot' => $profile->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code']), 'services_total' => $services, 'goods_total' => $goods, 'operation' => $operation, 'tax_rules' => $rules, 'configuration_hash' => app(CompanyFiscalConfiguration::class)->hash($issuer), 'certificate_fingerprint' => $credential->certificate_fingerprint, 'schema_package' => FiscalSchema::PACKAGE];
    }
}
