<?php

namespace App\Fiscal;

use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalIssuer;
use App\Models\FiscalSetting;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Services\Decimal;
use Illuminate\Validation\ValidationException;

final class NfePayload
{
    public function build(Tenant $tenant, Sale $sale, FiscalIssuer $issuer, array $operation): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['fiscal' => $message]);
        if ($issuer->environment !== 'homologation' || $issuer->provider !== 'sefaz_direct') {
            $fail('Este fluxo atende somente SEFAZ direta em homologação. Produção não habilitada.');
        }
        $profile = BusinessProfile::findOrFail(1);
        $configService = app(NfeCompanyConfiguration::class);
        $modelConfiguration = $configService->forIssuer($issuer);
        $c = $modelConfiguration->configuration;
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $configurationHash = $configService->hash($issuer);
        if (! $modelConfiguration->enabled || TaxpayerDocument::normalize((string) $profile->document) !== $issuer->document
            || ! $credential->valid_from || ! $credential->valid_until || $credential->valid_from->isFuture() || $credential->valid_until->isPast()
            || ! $modelConfiguration->validated_hash || ! hash_equals($modelConfiguration->validated_hash, $configurationHash)) {
            $fail('Valide o A1 e a configuração própria da NF-e antes de emitir. CSC não é exigido para o modelo 55.');
        }
        $configService->crt($profile->tax_regime, (string) ($c['crt'] ?? ''));
        $profileFields = $profile->only(['legal_name','trade_name','document','state_registration','tax_regime','street','number','district','city','state','postal_code','municipality_code']);
        \Illuminate\Support\Facades\Validator::make($profileFields, [
            'legal_name'=>'required|string|max:60','street'=>'required|string|max:60','number'=>'required|string|max:60','district'=>'required|string|max:60',
            'city'=>'required|string|max:60','state'=>'required|alpha|size:2','postal_code'=>'required|regex:/^[0-9]{5}-?[0-9]{3}$/D',
            'municipality_code'=>'required|digits:7','state_registration'=>'required|regex:/^[0-9]{2,14}$/D',
        ])->validate();
        $stateCode = NfeRecipient::stateCode((string) $profile->state);
        if (! $stateCode || ! str_starts_with((string) $profile->municipality_code, $stateCode)) { $fail('Confira a UF do emitente e a correspondência do código IBGE.'); }
        if (! preg_match('/^[0-9]{14}$/D', $issuer->document) || ! TaxpayerDocument::cnpj($issuer->document)
            || ($operation['destination_state'] ?? '') !== $profile->state || ($operation['presence'] ?? '') !== '1'
            || ! isset($operation['consumer_final']) || ! is_bool($operation['consumer_final'])) {
            $fail('A NF-e inicial atende venda presencial interna de mercadorias a destinatário identificado.');
        }
        if (! $sale->customer_id) { $fail('Selecione um cliente identificado e complete seu endereço fiscal para NF-e.'); }
        if ($sale->status !== 'completed') {
            $fail('Venda cancelada não pode ser emitida.');
        }
        $sale->load('items', 'customer');
        if (! $sale->customer) { $fail('Cliente não encontrado para esta venda.'); }
        $recipient = app(NfeRecipient::class)->snapshot($sale->customer, (string) $profile->state);
        if ($recipient['fiscal_ie_indicator'] === '9' && ! $operation['consumer_final']) { $fail('Destinatário não contribuinte exige operação de consumidor final neste fluxo de NF-e.'); }
        $items = [];
        $rules = [];
        $commercialLines = [];
        $goods = '0.00';
        $goodsGross = '0.00';
        $goodsDiscount = '0.00';
        foreach ($sale->items->sortBy('id') as $line) {
            if ($line->product_id && $line->service_id) {
                $fail('Item com vínculos ambíguos: revisar o histórico antes da emissão.');
            }
            if ($line->fiscal_kind === 'service' || $line->service_id) {
                $fail('A emissão direta inicial aceita somente mercadorias. Serviços municipais e vendas mistas exigem segregação fiscal específica; não serão omitidos silenciosamente.');
            }
            if ($line->fiscal_kind !== 'product' || ! $line->product_id) {
                $fail('Venda antiga sem classificação fiscal congelada. Faça revisão assistida antes de emitir.');
            }
            $product = Product::findOrFail($line->product_id);
            $rule = app(FiscalRuleResolver::class)->resolve($product);
            if (! $rule || ! ($rule['available'] ?? false) || empty($rule['accountant_reference'])) {
                $fail('Produto '.$product->name.': '.app(FiscalRuleResolver::class)->explain($rule));
            }
            if (! preg_match('/^[0-9]{8}$/D', (string) $product->ncm) || ! preg_match('/^[0-8]$/D', (string) $product->origin) || ! $product->unit) {
                $fail('Produto '.$product->name.': confira NCM, origem e unidade.');
            }
            $amounts = app(NfcePayload::class)->fiscalAmounts((string) $line->quantity, (string) $line->unit_price,
                (string) $line->gross_total, (string) $line->discount_amount, (string) $line->total);
            $tax = app(TaxProfile::class)->fields($rule['parameters'], $amounts['net'], (string) $c['crt']);
            $rules[$product->id] = ['parameters' => $rule['parameters'], 'accountant_reference' => $rule['accountant_reference'], 'validated_at' => $rule['validated_at'], 'source' => $rule['source'], 'profile_id' => $rule['profile_id'] ?? null, 'profile_revision' => $rule['profile_revision'] ?? null, 'group_id' => $rule['group_id'] ?? null];
            $items[] = array_merge($tax, [
                'numero_item' => (string) (count($items) + 1), 'codigo_ncm' => $product->ncm, 'codigo_produto' => $product->sku,
                'descricao' => $line->description, 'quantidade_comercial' => $amounts['quantity'], 'quantidade_tributavel' => $amounts['quantity'],
                'valor_unitario_comercial' => $amounts['unit_price'], 'valor_unitario_tributavel' => $amounts['unit_price'],
                'valor_bruto' => $amounts['gross'], 'valor_desconto' => $amounts['discount'],
                'unidade_comercial' => $product->unit, 'unidade_tributavel' => $product->unit, 'icms_origem' => $product->origin,
            ]);
            $commercialLines[] = ['sale_item_id' => $line->id, 'product_id' => $line->product_id,
                'quantity' => $amounts['quantity'], 'offered_unit_price' => $amounts['commercial_unit_price'],
                'gross' => $amounts['gross'], 'discount' => $amounts['discount'], 'net' => $amounts['net'],
                'fiscal_unit_price' => $amounts['unit_price']];
            $goods = bcadd($goods, $amounts['net'], 2);
            $goodsGross = bcadd($goodsGross, $amounts['gross'], 2);
            $goodsDiscount = bcadd($goodsDiscount, $amounts['discount'], 2);
        }
        if (! $items || bccomp($goods, '0', 2) <= 0) {
            $fail('Não há mercadorias com valor positivo para NF-e.');
        }
        if (bccomp($goods, (string) $sale->total, 2) !== 0
            || bccomp(bcsub($goodsGross, $goodsDiscount, 2), $goods, 2) !== 0) {
            $fail('Total comercial não corresponde aos itens, bruto e descontos.');
        }
        $payment = app(NfcePayload::class)->paymentEvidence($sale, $operation);
        $payload = [
            'cnpj_emitente' => $issuer->document, 'data_emissao' => now()->setTimezone($c['timezone'])->toIso8601String(),
            'presenca_comprador' => '1', 'modalidade_frete' => '9', 'local_destino' => '1',
            'natureza_operacao' => $c['natureza_operacao'], 'indicador_inscricao_estadual_destinatario' => '9', 'items' => $items,
            'formas_pagamento' => [['forma_pagamento' => $payment['fiscal_code'], 'valor_pagamento' => $payment['total']]],
            'valor_total' => $goods, 'valor_produtos' => $goodsGross, 'valor_desconto' => $goodsDiscount,
        ];
        // Required legal homologation label; business/issuer data are never fixtures.
        $payload['items'][0]['descricao'] = 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        $payload['nome_destinatario'] = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
        $payload[strlen($recipient['document']) === 11 ? 'cpf_destinatario' : 'cnpj_destinatario'] = $recipient['document'];
        $payload['indicador_inscricao_estadual_destinatario'] = $recipient['fiscal_ie_indicator'];
        $payload['recipient'] = $recipient;
        $payload['consumer_final'] = $operation['consumer_final'];
        $payload['model'] = 55;

        return [
            'payload' => $payload,
            'issuer_snapshot' => $profile->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code']),
            'configuration_snapshot' => array_intersect_key($c, array_flip(['timezone', 'crt', 'natureza_operacao', 'series', 'next_number', 'cancellation_minutes', 'uf_validation_reference', 'model'])),
            'commercial_lines' => $commercialLines, 'payment_evidence' => $payment,
            'recipient_snapshot' => $recipient, 'model' => 55, 'services_total' => '0.00', 'goods_total' => $goods, 'operation' => $operation,
            'tax_rules' => $rules, 'configuration_hash' => $configurationHash,
            'certificate_fingerprint' => $credential->certificate_fingerprint, 'schema_package' => FiscalSchema::PACKAGE,
        ];
    }

}
