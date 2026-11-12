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

final class NfcePayload
{
    public function build(Tenant $tenant, Sale $sale, FiscalIssuer $issuer, array $operation): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['fiscal' => $message]);
        if ($issuer->environment !== 'homologation' || $issuer->provider !== 'sefaz_direct') {
            $fail('Este fluxo atende somente SEFAZ direta em homologação. Produção não habilitada.');
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
        $configurationHash = app(CompanyFiscalConfiguration::class)->hash($issuer);
        if (! $credential->valid_from || ! $credential->valid_until || $credential->valid_from->isFuture()
            || $credential->valid_until->isPast() || ! $credential->validated_hash
            || ! hash_equals((string) $credential->validated_hash, $configurationHash)) {
            $fail('Certificado/configuração local precisa de validação antes da homologação direta.');
        }
        if (! FiscalSetting::find(1)?->nfce_accredited || empty($c['uf_validation_reference'])) {
            $fail('Confirme credenciamento e adequação da operação à UF com o contador.');
        }
        foreach (['timezone', 'crt', 'natureza_operacao', 'identification_limit'] as $field) {
            if (! isset($c[$field]) || trim((string) $c[$field]) === '') {
                $fail('Complete a configuração fiscal local: '.$field.'.');
            }
        }
        foreach (['legal_name', 'state_registration', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code', 'tax_regime'] as $field) {
            if (trim((string) $profile->$field) === '') {
                $fail('Complete o cadastro do emitente: '.$field.'.');
            }
        }
        if (($operation['destination_state'] ?? '') !== $profile->state || ($operation['consumer_final'] ?? false) !== true
            || ($operation['not_taxpayer'] ?? false) !== true || ($operation['presence'] ?? '') !== '1') {
            $fail('Este fluxo atende venda presencial interna a consumidor final não contribuinte. Operação diferente exige configuração/documento específico.');
        }
        if ($sale->status !== 'completed') {
            $fail('Venda cancelada não pode ser emitida.');
        }
        $sale->load('items', 'customer');
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
            $amounts = $this->fiscalAmounts((string) $line->quantity, (string) $line->unit_price,
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
            $fail('Não há mercadorias com valor positivo para NFC-e.');
        }
        if (bccomp($goods, (string) $sale->total, 2) !== 0
            || bccomp(bcsub($goodsGross, $goodsDiscount, 2), $goods, 2) !== 0) {
            $fail('Total comercial não corresponde aos itens, bruto e descontos.');
        }
        $payment = $this->paymentEvidence($sale, $operation);
        $payload = [
            'cnpj_emitente' => $issuer->document, 'data_emissao' => now()->setTimezone($c['timezone'])->toIso8601String(),
            'presenca_comprador' => '1', 'modalidade_frete' => '9', 'local_destino' => '1',
            'natureza_operacao' => $c['natureza_operacao'], 'indicador_inscricao_estadual_destinatario' => '9', 'items' => $items,
            'formas_pagamento' => [['forma_pagamento' => $payment['fiscal_code'], 'valor_pagamento' => $payment['total']]],
            'valor_total' => $goods, 'valor_produtos' => $goodsGross, 'valor_desconto' => $goodsDiscount,
        ];
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

        return [
            'payload' => $payload,
            'issuer_snapshot' => $profile->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code']),
            'configuration_snapshot' => array_intersect_key($c, array_flip(['timezone', 'crt', 'natureza_operacao', 'identification_limit', 'series', 'next_number', 'cancellation_minutes', 'uf_validation_reference', 'csc_required', 'qr_version'])),
            'commercial_lines' => $commercialLines, 'payment_evidence' => $payment,
            'services_total' => '0.00', 'goods_total' => $goods, 'operation' => $operation,
            'tax_rules' => $rules, 'configuration_hash' => $configurationHash,
            'certificate_fingerprint' => $credential->certificate_fingerprint, 'schema_package' => FiscalSchema::PACKAGE,
        ];
    }

    /** Fiscal vProd/discount stay frozen; vUnCom is derived from that same gross. */
    public function fiscalAmounts(string $quantity, string $offeredPrice, string $gross, string $discount, string $net): array
    {
        $quantity = Decimal::input($quantity, 3, 'fiscal', '0.001', '999999');
        $offeredPrice = Decimal::input($offeredPrice, 2, 'fiscal');
        $gross = Decimal::input($gross, 2, 'fiscal', '0.01');
        $discount = Decimal::input($discount, 2, 'fiscal');
        $net = Decimal::input($net, 2, 'fiscal', '0.01');
        if (bccomp($discount, $gross, 2) > 0 || bccomp(bcsub($gross, $discount, 2), $net, 2) !== 0
            || bccomp(Decimal::round(bcmul($quantity, $offeredPrice, 14), 2), $gross, 2) > 0) {
            throw ValidationException::withMessages(['fiscal' => 'Bruto, oferta, desconto e líquido divergem do histórico do item.']);
        }
        $unit = Decimal::round(bcdiv($gross, $quantity, 14), 10);
        Decimal::input($unit, 10, 'fiscal', '0', '99999999999.9999999999');
        if (bccomp(Decimal::round(bcmul($quantity, $unit, 14), 2), $gross, 2) !== 0) {
            throw ValidationException::withMessages(['fiscal' => 'O unitário fiscal não representa o bruto histórico com a precisão suportada.']);
        }

        return ['quantity' => $quantity, 'commercial_unit_price' => $offeredPrice,
            'unit_price' => $unit, 'gross' => $gross, 'discount' => $discount, 'net' => $net];
    }

    private function paymentEvidence(Sale $sale, array $operation): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['fiscal' => $message]);
        if (! $sale->paid || ! in_array($sale->payment_method, ['cash', 'pix', 'transfer'], true)) {
            $fail('Este fluxo exige quitação comprovada em dinheiro, Pix ou transferência, sem mistura de meios.');
        }
        $entries = $sale->entries()->orderBy('id')->lockForUpdate()->get();
        if ($entries->isEmpty()) {
            $fail('A quitação declarada não tem títulos e baixas financeiras comprováveis.');
        }
        $entrySnapshots = [];
        $totals = [];
        $scheduled = '0.00';
        foreach ($entries as $entry) {
            if ($entry->direction !== 'in' || $entry->purchase_id !== null || $entry->cancelled_at
                || bccomp((string) $entry->legacy_paid_amount, '0', 2) !== 0
                || bccomp((string) $entry->amount, '0', 2) <= 0
                || bccomp((string) $entry->paid_amount, (string) $entry->amount, 2) !== 0) {
                $fail('Há título não quitado, cancelado ou recebimento legado sem evidência; reconcilie antes de emitir.');
            }
            $scheduled = bcadd($scheduled, $entry->amount, 2);
            $totals[$entry->id] = '0.00';
            $entrySnapshots[] = ['entry_id' => $entry->id, 'amount' => $entry->amount, 'paid_amount' => $entry->paid_amount];
        }
        if (bccomp($scheduled, (string) $sale->total, 2) !== 0) {
            $fail('Os títulos não correspondem ao total vendido.');
        }
        $payments = Payment::whereIn('entry_id', $entries->modelKeys())->orderBy('id')->lockForUpdate()->get();
        if ($payments->isEmpty()) {
            $fail('Não há baixas financeiras para comprovar o pagamento.');
        }
        $received = '0.00';
        $receipts = [];
        foreach ($payments as $payment) {
            if ($payment->kind !== 'payment' || $payment->direction !== 'in' || $payment->reversal_of !== null
                || $payment->method !== $sale->payment_method || bccomp((string) $payment->amount, '0', 2) <= 0
                || ! $payment->paid_on || $payment->paid_on->toDateString() > now()->toDateString()) {
                $fail('Baixas mistas, futuras, estornadas ou incompatíveis com o meio declarado não são suportadas neste fluxo.');
            }
            $totals[$payment->entry_id] = bcadd($totals[$payment->entry_id], $payment->amount, 2);
            $received = bcadd($received, $payment->amount, 2);
            $receipts[] = ['payment_id' => $payment->id, 'entry_id' => $payment->entry_id,
                'user_id' => $payment->user_id, 'amount' => $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(), 'method' => $payment->method];
        }
        if (Payment::whereIn('reversal_of', $payments->modelKeys())->exists()) {
            $fail('Há reversão de recebimento a reconciliar antes de emitir.');
        }
        foreach ($entries as $entry) {
            if (bccomp($totals[$entry->id], $entry->paid_amount, 2) !== 0) {
                $fail('As baixas não correspondem ao valor liquidado de cada título.');
            }
        }
        if (bccomp($received, (string) $sale->total, 2) !== 0) {
            $fail('As baixas não comprovam o total da venda; troco e recebimento excedente exigem modelagem específica.');
        }
        $code = ['cash' => '01', 'transfer' => '18'][$sale->payment_method] ?? null;
        if ($sale->payment_method === 'pix') {
            $code = ['dynamic' => '17', 'static' => '20'][$operation['pix_mode'] ?? ''] ?? null;
            if ($code === null) {
                $fail('Informe explicitamente se o Pix recebido foi dinâmico ou estático antes da emissão.');
            }
        }

        return ['basis' => 'recorded_sale_payments', 'method' => $sale->payment_method,
            'pix_mode' => $sale->payment_method === 'pix' ? $operation['pix_mode'] : null,
            'fiscal_code' => $code, 'total' => $received, 'entries' => $entrySnapshots, 'receipts' => $receipts];
    }
}
