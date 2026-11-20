<?php

namespace App\Fiscal;

use App\Models\NfseDocument;
use App\Models\Sale;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

final class WorkOrderFiscalSplit
{
    public static function eligibleOrigin(Sale $sale): bool
    {
        return $sale->document_id && $sale->document?->type === 'work_order'
            && $sale->document->status === 'invoiced' && (int) $sale->document->sale_id === (int) $sale->id
            && $sale->items->contains('fiscal_kind', 'product') && $sale->items->contains('fiscal_kind', 'service');
    }

    public static function partition(Sale $sale): array
    {
        if ($sale->status !== 'completed' || !self::eligibleOrigin($sale)) {
            throw self::error('A separação exige uma OS mista faturada e vinculada à venda concluída.');
        }
        $totals = ['product' => '0.00', 'service' => '0.00'];
        $lines = ['product' => [], 'service' => []];
        foreach ($sale->items->sortBy('id') as $item) {
            $kind = $item->fiscal_kind;
            if (!isset($totals[$kind]) || !$item->product_id || $item->service_id
                || bccomp($item->total, '0', 2) < 0 || $item->gross_total === null
                || bccomp(bcsub($item->gross_total, $item->discount_amount, 2), $item->total, 2) !== 0) {
                throw self::error('Item legado, ambíguo ou com valor divergente. Confira a classificação e os totais antes de separar.');
            }
            $totals[$kind] = bcadd($totals[$kind], $item->total, 2);
            $lines[$kind][] = $item->only(['id', 'product_id', 'description', 'quantity', 'unit_price', 'gross_total', 'discount_amount', 'total']);
        }
        if (count($lines['service']) !== 1 || bccomp($totals['service'], '0', 2) <= 0 || bccomp($totals['product'], '0', 2) <= 0
            || bccomp(bcadd($totals['product'], $totals['service'], 2), $sale->total, 2) !== 0) {
            throw self::error('Confira o total da venda e eventuais acréscimos. Esta preparação atende uma linha de serviço e uma ou mais mercadorias.');
        }
        return ['sale_id' => $sale->id, 'work_order_id' => $sale->document_id, 'sale_total' => $sale->total,
            'goods' => $lines['product'], 'service' => $lines['service'][0], 'goods_total' => $totals['product'], 'services_total' => $totals['service']];
    }

    /** A transient read-only projection for existing municipal scope/tax validation. */
    public static function servicePortion(Sale $sale, array $split): Sale
    {
        $portion = clone $sale;
        $portion->setRelation('items', $sale->items->where('id', $split['service']['id'])->values());
        $portion->total = $split['services_total'];
        $portion->discount_total = $split['service']['discount_amount'];
        return $portion;
    }

    public static function forGoods(Tenant $tenant, Sale $sale, array $operation): ?array
    {
        if (empty($operation['segregate_work_order'])) {
            return null;
        }
        $split = self::partition($sale);
        $dps = NfseDocument::where('sale_id', $sale->id)->first();
        if (!$dps || $dps->environment !== 'restricted' || $dps->status !== 'draft_validated'
            || (int) ($dps->snapshot['tenant_id'] ?? 0) !== (int) $tenant->id
            || !hash_equals($dps->snapshot_sha256, Snapshot::hash($dps->snapshot))
            || !hash_equals($dps->xml_sha256, hash('sha256', $dps->signed_xml))
            || Snapshot::hash($dps->snapshot['work_order_split'] ?? []) !== Snapshot::hash($split)
            || bccomp($dps->total, $split['services_total'], 2) !== 0) {
            throw self::error('Prepare primeiro a DPS da parte de serviço desta OS e confira sua integridade.');
        }
        return $split + ['nfse_document_id' => $dps->id, 'dps_id' => $dps->dps_id];
    }

    public static function goodsPayments(array $evidence, ?array $split, array $allocation = []): array
    {
        if (!$split) {
            return $evidence['methods'];
        }
        if (count($evidence['methods']) === 1) {
            return [['forma_pagamento' => $evidence['methods'][0]['forma_pagamento'], 'valor_pagamento' => $split['goods_total']]];
        }
        $result = [];
        $sum = '0.00';
        foreach ($evidence['methods'] as $method) {
            $name = ['01' => 'cash', '17' => 'pix', '20' => 'pix', '18' => 'transfer'][$method['forma_pagamento']];
            $amount = \App\Services\Decimal::input((string) ($allocation[$name] ?? ''), 2, 'goods_payments', '0', $method['valor_pagamento']);
            unset($allocation[$name]);
            if (bccomp($amount, '0', 2) > 0) {
                $result[] = ['forma_pagamento' => $method['forma_pagamento'], 'valor_pagamento' => $amount];
            }
            $sum = bcadd($sum, $amount, 2);
        }
        if ($allocation || bccomp($sum, $split['goods_total'], 2) !== 0) {
            throw self::error('Informe a parte de cada meio destinada às mercadorias. A soma deve corresponder ao valor das mercadorias.');
        }
        return $result;
    }

    private static function error(string $message): ValidationException
    {
        return ValidationException::withMessages(['fiscal' => $message]);
    }
}
