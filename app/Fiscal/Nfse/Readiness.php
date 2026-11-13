<?php

namespace App\Fiscal\Nfse;

use App\Fiscal\TaxpayerDocument;
use App\Models\BusinessProfile;
use App\Models\NfseConfiguration;
use App\Models\Product;
use App\Models\Sale;

final class Readiness
{
    public const TRANSPORT_BLOCKER = 'Transmissão indisponível: contrato autenticado da API nacional e validação da resposta ainda não verificados. Esta DPS não é uma NFS-e autorizada.';

    /** draft_blockers prevent a local draft; blockers additionally describe external dependencies. */
    public function inspect(?NfseConfiguration $configuration, ?Sale $sale = null): array
    {
        $errors = [];
        $profile = BusinessProfile::find(1);
        $p = $configuration?->parameters ?? [];
        if (! $configuration || ! $configuration->enabled) {
            $errors[] = 'Configure e habilite a preparação local de DPS.';
        }
        if (! $configuration?->national_issuer_confirmed) {
            $errors[] = 'Confirme a habilitação do prestador no emissor nacional para o município.';
        }
        if ($configuration && ($configuration->environment !== 'restricted' || $configuration->cnpj !== TaxpayerDocument::normalize((string) $profile?->document))) {
            $errors[] = 'Configuração não corresponde ao CNPJ atual ou ao ambiente restrito.';
        }
        if (! $configuration?->certificate_valid_until || $configuration->certificate_valid_until->isPast()) {
            $errors[] = 'Envie um certificado A1 válido da empresa.';
        }
        if (! $profile || empty($profile->legal_name) || $profile->municipality_code !== ($p['municipality_code'] ?? null)) {
            $errors[] = 'Confira razão social e município do prestador em Minha empresa.';
        }
        $expected = ['mei' => '2', 'simples' => '3', 'presumido' => '1', 'real' => '1'];
        if (($expected[$profile?->tax_regime ?? ''] ?? null) !== (string) ($p['op_simp_nac'] ?? '')) {
            $errors[] = 'Situação no Simples diverge do cadastro atual da empresa.';
        }
        if (! in_array((string) ($p['op_simp_nac'] ?? ''), ['2', '3'], true) || ((string) ($p['op_simp_nac'] ?? '') === '3' && (string) ($p['reg_ap_trib_sn'] ?? '') !== '1')) {
            $errors[] = 'O corte inicial prepara somente MEI e ME/EPP com tributos federais e municipal pelo Simples; outros regimes exigem modelagem federal/IBS/CBS.';
        }
        if ($sale) {
            $sale->loadMissing('items', 'customer');
            if ($sale->customer_id) {
                $document = TaxpayerDocument::normalize((string) $sale->customer?->document);
                if ((! TaxpayerDocument::cpf($document) && ! TaxpayerDocument::cnpj($document)) || trim((string) $sale->customer?->name) === '') {
                    $errors[] = 'Complete nome e CPF/CNPJ válido do tomador identificado.';
                }
            }
            if ($sale->status !== 'completed' || bccomp((string) $sale->total, '0', 2) <= 0) {
                $errors[] = 'A venda deve estar concluída e ter total positivo.';
            }
            if ($sale->items->count() !== 1) {
                $errors[] = 'Prepare somente venda com uma única linha de serviço puro; operação mista ou múltiplas linhas não suportadas.';
            } else {
                $line = $sale->items->first();
                $product = $line->product_id ? Product::find($line->product_id) : null;
                if ($line->fiscal_kind !== 'service' || ! $product || $product->type !== 'service' || $line->service_id) {
                    $errors[] = 'A linha deve referenciar serviço do catálogo unificado; mercadorias ou serviço legado não são suportados.';
                }
                if (bccomp((string) $line->total, (string) $sale->total, 2) !== 0 || bccomp((string) $line->quantity, '0', 3) <= 0) {
                    $errors[] = 'Valor da linha e total da venda devem ser positivos e consistentes.';
                }
                if (bccomp((string) ($line->discount_amount ?? 0), '0', 2) !== 0 || bccomp((string) ($sale->discount_total ?? 0), '0', 2) !== 0) {
                    $errors[] = 'Descontos exigem classificação fiscal própria e não são suportados nesta preparação inicial.';
                }
                if (trim((string) $line->description) === '') {
                    $errors[] = 'Informe a descrição do serviço na venda.';
                }
            }
        }
        return ['environment' => 'restricted', 'transport_ready' => false, 'draft_ready' => $errors === [], 'draft_blockers' => array_values(array_unique($errors)), 'blockers' => array_values(array_unique([...$errors, self::TRANSPORT_BLOCKER, 'Conferência de retenções, local de incidência e exigências tributárias/IBS/CBS permanece necessária antes de qualquer transmissão.']))];
    }
}
