<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class Checkout
{
    public function complete(array $data, int $userId): Sale
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId) {
                $existing = Sale::where('request_key', $data['request_key'])->first();
                if ($existing) {
                    return $existing;
                }
                $method = FinancialService::paymentMethod((string) $data['payment_method']);
                $sale = Sale::create(['request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'payment_method' => $method, 'paid' => false, 'due_date' => $data['due_date'] ?? null, 'total' => 0, 'cost_total' => 0, 'notes' => $data['notes'] ?? null]);
                $lines = $data['items'];
                if ($lines === [] || count($lines) > 50) {
                    throw ValidationException::withMessages(['items' => 'Informe de 1 a 50 itens.']);
                }
                usort($lines, fn ($a, $b) => strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
                $total = '0';
                $cost = '0';
                foreach ($lines as $i => $line) {
                    if (! in_array($line['kind'], ['product', 'service'], true)) {
                        throw ValidationException::withMessages(['items' => 'Tipo de item inválido.']);
                    }
                    $product = $line['kind'] === 'product';
                    $item = $product ? Product::where('active', true)->lockForUpdate()->findOrFail($line['id'])
                        : Product::resolveLegacyService((int) $line['id'], true);
                    $qty = Decimal::input((string) $line['quantity'], 3, 'items', '0.001', '999999');
                    $price = Decimal::input((string) $line['unit_price'], 2, 'items', '0', '999999999999.99');
                    $unitCost = $item instanceof Product ? $item->pricingCost() : $item->cost;
                    $amount = Decimal::input(Decimal::round(bcmul($qty, $price, 8)), 2, 'items');
                    SaleItem::create(['fiscal_kind' => $item instanceof Product ? $item->type : 'service', 'sale_id' => $sale->id, 'product_id' => $product ? $item->id : null, 'service_id' => $product ? null : (int) $line['id'], 'description' => $item->name, 'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => $unitCost, 'total' => $amount]);
                    if ($product && $item->controlsStock()) {
                        app(Inventory::class)->move($item->id, 'sale', $qty, $unitCost, 'Venda #'.$sale->id, $userId, 'sale-'.$sale->id.'-'.$i, $sale->id);
                    }
                    $total = bcadd($total, $amount, 2);
                    $cost = bcadd($cost, bcmul($qty, $unitCost, 8), 8);
                }
                $cost = Decimal::round($cost, 4);
                Decimal::input($total, 2, 'items');
                Decimal::input($cost, 4, 'items', '0', '999999999999.9999');
                $sale->update(['total' => $total, 'cost_total' => $cost]);
                if (bccomp($total, '0', 2) === 0) {
                    $sale->update(['paid' => true]);

                    return $sale;
                }
                $finance = app(FinancialService::class);
                $entries = $finance->schedule([
                    'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:sale-plan:'.$sale->request_key)->toString(),
                    'sale_id' => $sale->id, 'direction' => 'in', 'description' => 'Venda #'.$sale->id,
                    'category' => 'Vendas', 'amount' => $total,
                    'due_date' => $data['due_date'] ?? ((bool) $data['paid'] ? now()->toDateString() : ''),
                    'installments' => $data['installments'] ?? 1,
                ], $userId);
                if ((bool) $data['paid']) {
                    foreach ($entries as $entry) {
                        $finance->pay($entry->id, $entry->amount, now()->toDateString(), $method, $userId,
                            Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:immediate-payment:'.$entry->request_key)->toString());
                    }
                }

                return $sale->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request may win the unique request_key insert. The losing
            // transaction has rolled back; use a current read, including in a parent transaction.
            $existing = Sale::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    public function cancel(int $id, int $userId, bool $refundConfirmed = false): void
    {
        DB::connection('tenant')->transaction(function () use ($id, $userId, $refundConfirmed) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status === 'cancelled') {
                return;
            }
            app(FinancialService::class)->cancelSale($sale->id, $userId, $refundConfirmed);
            foreach ($sale->items()->whereNotNull('product_id')->orderBy('product_id')->get() as $line) {
                // Historical participation, not today's product type, determines a stock return.
                $hadStock = StockMovement::where('sale_id', $sale->id)->where('product_id', $line->product_id)->where('type', 'sale')->exists();
                if (! $hadStock) {
                    continue;
                }
                $product = Product::lockForUpdate()->findOrFail($line->product_id);
                if (! $product->controlsStock()) {
                    throw ValidationException::withMessages(['product_id' => 'Reative o controle de estoque de '.$product->name.' antes de estornar a venda que originalmente baixou seu saldo.']);
                }
                app(Inventory::class)->move($line->product_id, 'return', $line->quantity, $line->unit_cost, 'Estorno venda #'.$sale->id, $userId, 'cancel-'.$line->id, $sale->id);
            }
            $sale->update(['status' => 'cancelled', 'paid' => false]);
        }, 3);
    }
}
