<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public function move(int $productId, string $type, string $qty, string $cost, string $reason, int $userId, string $key, ?int $saleId = null): Product
    {
        return DB::connection('tenant')->transaction(function () use ($productId, $type, $qty, $cost, $reason, $userId, $key, $saleId) {
            $p = Product::lockForUpdate()->findOrFail($productId);
            if (StockMovement::where('request_key', $key)->exists()) {
                return $p;
            }
            if (! in_array($type, ['in', 'out', 'sale', 'return']) || bccomp($qty, '0', 3) <= 0 || bccomp($cost, '0', 4) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Movimentação inválida.']);
            }
            $incoming = in_array($type, ['in', 'return']);
            $balance = $incoming ? bcadd($p->quantity, $qty, 3) : bcsub($p->quantity, $qty, 3);
            if (bccomp($balance, '0', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Estoque insuficiente para '.$p->name.'.']);
            }
            $unitCost = $incoming ? $cost : $p->average_cost;
            $avg = $p->average_cost;
            if ($incoming) {
                $value = bcadd(bcmul($p->quantity, $avg, 8), bcmul($qty, $cost, 8), 8);
                $avg = Decimal::round(bcdiv($value, $balance, 8), 4);
            }
            $p->update(['quantity' => $balance, 'average_cost' => $avg]);
            StockMovement::create(['request_key' => $key, 'product_id' => $p->id, 'user_id' => $userId, 'sale_id' => $saleId, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $unitCost, 'balance' => $balance, 'average_cost' => $avg, 'reason' => $reason]);

            return $p;
        }, 3);
    }
}
