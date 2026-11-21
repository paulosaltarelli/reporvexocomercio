<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\Sale;

/** The operation controller authorizes its order/cart before calling this renderer. */
final class OperationalReceipt
{
    public function render(Sale $sale, string $returnUrl)
    {
        $sale->loadMissing('items', 'customer', 'creator');
        $ledger = FinancialEntry::where('sale_id', $sale->id)->where('direction', 'in')
            ->selectRaw('COUNT(*) AS entry_count, COALESCE(SUM(paid_amount), 0) AS received,
                COALESCE(SUM(CASE WHEN cancelled_at IS NULL THEN amount - paid_amount ELSE 0 END), 0) AS outstanding')
            ->first();

        return response()->view('operations.receipt', [
            'sale' => $sale, 'receiptLedger' => $ledger, 'received' => $ledger->received,
            'paymentPlan' => app(SalePayments::class)->rows($sale->id),
            'passedCardFee' => app(SalePayments::class)->passedTotal($sale->id),
            'receiptBackUrl' => $returnUrl, 'receiptSale' => true,
        ])->header('Cache-Control', 'private, no-store');
    }
}
