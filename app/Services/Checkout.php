<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Service;
use Illuminate\Support\Facades\DB;

class Checkout
{
    public function complete(array $data, int $userId): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            $existing = Sale::where('request_key', $data['request_key'])->first();
            if ($existing) {
                return $existing;
            }
            $sale = Sale::create(['request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'payment_method' => $data['payment_method'], 'paid' => $data['paid'], 'due_date' => $data['due_date'] ?? null, 'total' => 0, 'cost_total' => 0, 'notes' => $data['notes'] ?? null]);
            $lines = $data['items'];
            usort($lines, fn ($a, $b) => strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
            $total = '0';
            $cost = '0';
            foreach ($lines as $i => $line) {
                $product = $line['kind'] === 'product';
                $item = ($product ? Product::query() : Service::query())->where('active', true)->lockForUpdate()->findOrFail($line['id']);
                $qty = (string) $line['quantity'];
                $price = (string) $line['unit_price'];
                $unitCost = $product ? $item->average_cost : $item->cost;
                $amount = Decimal::round(bcmul($qty, $price, 8));
                SaleItem::create(['sale_id' => $sale->id, 'product_id' => $product ? $item->id : null, 'service_id' => $product ? null : $item->id, 'description' => $item->name, 'quantity' => $qty, 'unit_price' => $price, 'unit_cost' => $unitCost, 'total' => $amount]);
                if ($product) {
                    app(Inventory::class)->move($item->id, 'sale', $qty, $unitCost, 'Venda #'.$sale->id, $userId, 'sale-'.$sale->id.'-'.$i, $sale->id);
                }
                $total = bcadd($total, $amount, 2);
                $cost = bcadd($cost, bcmul($qty, $unitCost, 4), 4);
            }
            $sale->update(['total' => $total, 'cost_total' => $cost]);

            return $sale;
        }, 3);
    }

    public function cancel(int $id, int $userId): void
    {
        DB::connection('tenant')->transaction(function () use ($id, $userId) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status === 'cancelled') {
                return;
            }
            foreach ($sale->items()->whereNotNull('product_id')->orderBy('product_id')->get() as $line) {
                app(Inventory::class)->move($line->product_id, 'return', $line->quantity, $line->unit_cost, 'Estorno venda #'.$sale->id, $userId, 'cancel-'.$line->id, $sale->id);
            }
            $sale->update(['status' => 'cancelled']);
        }, 3);
    }
}
