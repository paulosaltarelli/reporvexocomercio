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
        $qty = Decimal::input($qty, 3, 'quantity', '0.001', '999999999');
        $cost = Decimal::input($cost, 4, 'unit_cost', '0', '999999999.9999');
        if (! in_array($type, ['in', 'out', 'sale', 'return'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimentação inválido.']);
        }

        return DB::connection('tenant')->transaction(function () use ($productId, $type, $qty, $cost, $reason, $userId, $key, $saleId) {
            $p = Product::lockForUpdate()->findOrFail($productId);
            $existing = StockMovement::where('request_key', $key)->first();
            if ($existing) {
                $same = (int) $existing->product_id === $productId && $existing->type === $type
                    && bccomp($existing->quantity, $qty, 3) === 0
                    && (int) $existing->user_id === $userId && ($existing->sale_id === null ? null : (int) $existing->sale_id) === $saleId
                    && $existing->reason === $reason;
                if (in_array($type, ['in', 'return'], true)) {
                    $same = $same && bccomp($existing->unit_cost, $cost, 4) === 0;
                }
                if (! $same) {
                    throw ValidationException::withMessages(['request_key' => 'Esta chave já pertence a outra movimentação. Atualize o formulário antes de continuar.']);
                }

                return $p;
            }
            $incoming = in_array($type, ['in', 'return']);
            $balance = $incoming ? bcadd($p->quantity, $qty, 3) : bcsub($p->quantity, $qty, 3);
            if (bccomp($balance, '0', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Estoque insuficiente para '.$p->name.'.']);
            }
            Decimal::input($balance, 3, 'quantity', '0', '9999999999999.999');
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
