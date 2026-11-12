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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalReadiness
{
    private const UF_CODES = ['AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29', 'CE' => '23', 'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21', 'MT' => '51', 'MS' => '50', 'MG' => '31', 'PA' => '15', 'PB' => '25', 'PR' => '41', 'PE' => '26', 'PI' => '22', 'RJ' => '33', 'RN' => '24', 'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42', 'SP' => '35', 'SE' => '28', 'TO' => '17'];

    public function inspect(Tenant $tenant, ?Sale $sale = null): array
    {
        if (! $tenant->active || DB::connection('tenant')->getDatabaseName() !== $tenant->db_name) {
            throw ValidationException::withMessages(['fiscal' => 'Contexto da empresa inválido.']);
        }
        $profile = BusinessProfile::find(1);
        $settings = FiscalSetting::find(1);
        $issues = [];
        $add = function (string $code, string $message) use (&$issues): void {
            $issues[] = ['code' => $code, 'message' => $message];
        };
        $issuer = $profile?->only(['legal_name', 'trade_name', 'document', 'municipal_registration', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code']) ?? [];
        foreach (['legal_name' => 'razão social', 'state_registration' => 'inscrição estadual', 'tax_regime' => 'regime tributário', 'street' => 'logradouro', 'number' => 'número do endereço', 'district' => 'bairro', 'city' => 'município'] as $field => $label) {
            if (trim((string) ($issuer[$field] ?? '')) === '') {
                $add('issuer.'.$field, 'Complete '.$label.' em Minha empresa.');
            }
        }
        if (! empty($issuer['tax_regime']) && ! in_array($issuer['tax_regime'], ['mei', 'simples', 'presumido', 'real'], true)) {
            $add('issuer.tax_regime_invalid', 'Revise o regime tributário informado em Minha empresa.');
        }
        if (! TaxpayerDocument::cnpj((string) ($issuer['document'] ?? ''))) {
            $add('issuer.document', 'Informe CNPJ com formato e dígitos verificadores válidos em Minha empresa.');
        }
        $uf = $issuer['state'] ?? '';
        if (! isset(self::UF_CODES[$uf])) {
            $add('issuer.state', 'Informe a UF do emitente em Minha empresa.');
        }
        $municipality = (string) ($issuer['municipality_code'] ?? '');
        if (! preg_match('/^[0-9]{7}$/D', $municipality) || (isset(self::UF_CODES[$uf]) && ! str_starts_with($municipality, self::UF_CODES[$uf]))) {
            $add('issuer.municipality_code', 'Confira o código IBGE de sete dígitos e sua correspondência com a UF.');
        }
        if (! preg_match('/^[0-9]{8}$/D', str_replace('-', '', (string) ($issuer['postal_code'] ?? '')))) {
            $add('issuer.postal_code', 'Complete o CEP de oito dígitos em Minha empresa.');
        }
        if (! $settings?->nfce_accredited) {
            $add('issuer.accreditation', 'Confirme o credenciamento NFC-e em homologação com a SEFAZ da UF.');
        }
        if (! $settings?->accountant_name) {
            $add('issuer.accountant', 'Informe o contador responsável pela validação tributária.');
        }
        if (! $settings?->operation) {
            $add('issuer.operation', 'Informe se a operação é presencial, delivery ou ambas.');
        }
        $configuredIssuer = FiscalIssuer::where('document', TaxpayerDocument::normalize((string) ($issuer['document'] ?? '')))->where('environment', 'homologation')->first();
        $credential = $configuredIssuer ? FiscalCredential::where('issuer_id', $configuredIssuer->id)->first() : null;
        if (! $configuredIssuer || empty($configuredIssuer->validated_configuration['uf_validation_reference'])) {
            $add('nfce.uf_review', 'A adequação do modelo 65 à UF e à operação exige validação; certas vendas podem exigir NF-e.');
        }
        if (! $credential) {
            $add('credentials.pending', 'Configure o certificado A1 e as credenciais de homologação em Minha empresa.');
        } elseif ($credential->valid_until->isPast() || ! $credential->validated_hash || ! hash_equals($credential->validated_hash, app(CompanyFiscalConfiguration::class)->hash($configuredIssuer))) {
            $add('credentials.validation', 'Confira a validade do certificado e valide localmente a configuração fiscal atual.');
        }
        if (Product::where('type', 'product')->whereNotIn('id', FiscalItemRule::select('product_id'))->exists()) {
            $add('tax.validation', 'Há produtos sem regra fiscal específica validada pelo contador.');
        }
        if (! $configuredIssuer?->enabled) {
            $add('transport.disabled', 'A emissão depende da configuração de homologação em Minha empresa. Registrar conferência não emite nota.');
        }
        if ($tenant->fiscal_demonstration ?? true) {
            $add('production.demonstration', 'Empresa classificada como demonstração: produção fiscal bloqueada.');
        }

        $snapshot = ['schema_version' => 1, 'purpose' => 'review_only', 'environment' => 'homologation', 'candidate_model' => 65, 'issuer' => $issuer,
            'configuration' => $settings?->only(['provider', 'operation', 'nfce_accredited', 'accountant_name', 'accountant_email']) ?? []];
        if ($sale) {
            $sale->load('items', 'customer');
            if ($sale->status !== 'completed') {
                $add('sale.status', 'Venda cancelada não pode originar nova emissão.');
            }
            $totals = ['goods' => '0.00', 'services' => '0.00', 'unclassified' => '0.00'];
            $lines = [];
            $products = Product::whereIn('id', $sale->items->pluck('product_id')->filter())->get()->keyBy('id');
            foreach ($sale->items->sortBy('id') as $item) {
                $kind = $item->fiscal_kind === 'service' || $item->service_id ? 'services' : ($item->fiscal_kind === 'product' ? 'goods' : 'unclassified');
                $totals[$kind] = bcadd($totals[$kind], $item->total, 2);
                $line = $item->only(['id', 'product_id', 'service_id', 'description', 'quantity', 'unit_price', 'gross_total', 'discount_amount', 'total']);
                $line['kind'] = $kind;
                // Prices/amounts come exclusively from sale history. Tax metadata
                // below is current at review time, NOT historical or approved.
                if ($kind === 'goods') {
                    $product = $products->get($item->product_id);
                    $line['tax_metadata_at_review'] = $product?->only(['ncm', 'origin', 'unit']) ?? [];
                    if (! preg_match('/^[0-9]{8}$/D', (string) $product?->ncm)) {
                        $add('item.'.$item->id.'.ncm', 'Item #'.$item->id.': complete e valide o NCM do produto.');
                    }
                    if (! preg_match('/^[0-8]$/D', (string) $product?->origin) || ! $product?->unit) {
                        $add('item.'.$item->id.'.classification', 'Item #'.$item->id.': complete origem e unidade fiscal.');
                    }
                } elseif ($kind === 'unclassified') {
                    $add('item.'.$item->id.'.kind', 'Item #'.$item->id.': vínculos ambíguos ou ausentes; revisar cadastro legado.');
                }
                $lines[] = $line;
            }
            $sum = bcadd(bcadd($totals['goods'], $totals['services'], 2), $totals['unclassified'], 2);
            if (bccomp($sum, $sale->total, 2) !== 0) {
                $add('sale.total', 'Soma dos itens diverge da venda; faça a reconciliação antes da emissão.');
            }
            if (bccomp($totals['goods'], '0', 2) <= 0) {
                $add('sale.no_goods', 'Não há mercadorias com valor positivo para NFC-e nesta venda.');
            }
            if (bccomp($totals['services'], '0', 2) > 0) {
                $add('sale.services', 'Serviços municipais ficam fora da NFC-e e dependem de NFS-e separada.');
            }
            $recipient = $sale->customer?->only(['name', 'document', 'address']) ?? null;
            if ($recipient && trim((string) $recipient['document']) !== '' && ! TaxpayerDocument::cpf($recipient['document']) && ! TaxpayerDocument::cnpj($recipient['document'])) {
                $add('recipient.document', 'Confira o CPF/CNPJ do cliente; formato ou dígitos verificadores inválidos.');
            }
            $add('recipient.uf_rules', 'Identificação, endereço fiscal e limites do destinatário dependem das regras da UF e da operação.');
            $snapshot['sale'] = $sale->only(['id', 'status', 'total', 'payment_method', 'created_at']);
            $snapshot['recipient'] = $recipient;
            $snapshot['items'] = $lines;
            $snapshot['totals'] = $totals;
            // This is an allocation of the declared commercial payment method,
            // NOT a receipt, tax payment code or a complete NFC-e payment group.
            $snapshot['payment_review'] = ['commercial_method' => $sale->payment_method, 'allocation' => $totals, 'basis' => 'sale_amount_not_receipts'];
        }

        return ['ready_to_transmit' => false, 'blockers' => $issues, 'snapshot' => $snapshot];
    }
}
