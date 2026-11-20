<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Inventory
{
    public function saleWarning(int $saleId): ?string
    {
        if (app(CompanyOperations::class)->current()['negative_stock'] !== 'warn') {
            return null;
        }

        $products = Product::whereIn('id', StockMovement::query()
            ->select('product_id')->where('sale_id', $saleId)->where('type', 'sale')->where('balance', '<', 0))
            ->orderBy('name')->pluck('name');

        return $products->isEmpty() ? null : 'Venda registrada com estoque negativo: '.$products->implode(', ').'.';
    }

    public function values(Collection $products): array
    {
        $latest = StockMovement::whereIn('product_id', $products->modelKeys())
            ->selectRaw('product_id, MAX(id) AS movement_id')->groupBy('product_id')->pluck('movement_id', 'product_id');
        $balances = OperationRecord::where('action', 'stock_valuation')->whereIn('after->movement_id', $latest->values()->all())
            ->get()->mapWithKeys(fn ($record) => [$record->after['movement_id'] => $record->after['balance_value']]);
        $values = [];
        foreach ($products as $product) {
            $values[$product->id] = $balances->get($latest->get($product->id)) ?? bcmul($product->quantity, $product->average_cost, 7);
        }

        return $values;
    }

    public function totalValue(): string
    {
        return DB::connection('tenant')->transaction(function () {
            $total = '0.0000000';
            Product::select('id', 'quantity', 'average_cost')->where('quantity', '<>', 0)->chunkById(500, function ($products) use (&$total) {
                foreach ($this->values($products) as $value) {
                    $total = bcadd($total, $value, 7);
                }
            });

            return $total;
        });
    }

    public function movementValue(string $key): ?string
    {
        return OperationRecord::where('action', 'stock_valuation')->where('after->request_key', $key)
            ->first()?->after['movement_value'] ?? null;
    }

    public function saleValueForItem(int $saleId, int $itemId): ?string
    {
        return OperationRecord::where('action', 'stock_valuation')->where('after->sale_id', $saleId)
            ->where('after->sale_item_id', $itemId)->first()?->after['movement_value'] ?? null;
    }

    public function move(int $productId, string $type, string $qty, string $cost, string $reason, int $userId, string $key, ?int $saleId = null, ?string $totalCost = null, ?int $saleItemId = null): Product
    {
        $qty = Decimal::input($qty, 3, 'quantity', '0.001', '999999999');
        $cost = Decimal::input($cost, 4, 'unit_cost', '0', '999999999.9999');
        if (! in_array($type, ['in', 'out', 'sale', 'return'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimentação inválido.']);
        }

        return DB::connection('tenant')->transaction(function () use ($productId, $type, $qty, $cost, $reason, $userId, $key, $saleId, $totalCost, $saleItemId) {
            $configuration = app(CompanyOperations::class)->lock();
            if (! $configuration['inventory'] && $type !== 'return') {
                throw ValidationException::withMessages(['inventory' => 'A gestão de estoque está desabilitada para esta empresa.']);
            }
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
            if (! $p->controlsStock()) {
                throw ValidationException::withMessages(['product_id' => 'Este item não controla estoque e não permite movimentações.']);
            }
            $incoming = in_array($type, ['in', 'return']);
            $balance = $incoming ? bcadd($p->quantity, $qty, 3) : bcsub($p->quantity, $qty, 3);
            if (! $incoming && $configuration['negative_stock'] === 'block' && bccomp($balance, '0', 3) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Estoque insuficiente para '.$p->name.'.']);
            }
            Decimal::input(ltrim($balance, '-'), 3, 'quantity', '0', '9999999999999.999');
            $unitCost = $incoming ? $cost : $p->average_cost;
            $lastMovement = StockMovement::where('product_id', $p->id)->orderByDesc('id')->sharedLock()->first();
            $valuation = $lastMovement ? OperationRecord::where('action', 'stock_valuation')
                ->where('after->movement_id', $lastMovement->id)->sharedLock()->first()?->after : null;
            $startValue = $valuation['balance_value'] ?? bcmul($p->quantity, $p->average_cost, 7);
            $movementValue = $totalCost === null ? bcmul($qty, $unitCost, 7)
                : Decimal::input($totalCost, 7, 'items', '0', '9999999999999999999999.9999999');
            $avg = $p->average_cost;
            if ($incoming) {
                $endValue = bcadd($startValue, $movementValue, 7);
                if (bccomp($p->quantity, '0', 3) < 0) {
                    // Preserve the historical valuation of short stock until the deficit is covered.
                    $endValue = bccomp($balance, '0', 3) > 0
                        ? bcdiv(bcmul($movementValue, $balance, 10), $qty, 7) : bcmul($balance, $avg, 7);
                }
                if (bccomp($balance, '0', 3) > 0) {
                    $avg = Decimal::round(bcdiv($endValue, $balance, 12), 4);
                }
            } else {
                if (bccomp($p->quantity, '0', 3) > 0) {
                    $movementValue = bccomp($balance, '0', 3) >= 0
                        ? bcdiv(bcmul($startValue, $qty, 10), $p->quantity, 7)
                        : bcadd($startValue, bcmul(substr($balance, 1), $avg, 7), 7);
                }
                if ($type === 'sale' && bccomp($balance, '0', 3) > 0
                    && bccomp($startValue, bcmul($p->quantity, $avg, 7), 7) !== 0) {
                    // The sale stores COGS at four places. Leave its fractional residue
                    // in stock so a sequence of partial sales consumes the entire value.
                    $rounded = Decimal::round($movementValue, 4);
                    $movementValue = bccomp($rounded, $startValue, 7) > 0 ? $startValue : $rounded;
                }
                $endValue = bcsub($startValue, $movementValue, 7);
            }
            $p->update(['quantity' => $balance, 'average_cost' => $avg]);
            $movement = StockMovement::create(['request_key' => $key, 'product_id' => $p->id, 'user_id' => $userId, 'sale_id' => $saleId, 'type' => $type, 'quantity' => $qty, 'unit_cost' => $unitCost, 'balance' => $balance, 'average_cost' => $avg, 'reason' => $reason]);

            OperationRecord::create(['version' => 0, 'user_id' => $userId, 'action' => 'stock_valuation', 'before' => [],
                'after' => ['movement_id' => $movement->id, 'request_key' => $key, 'sale_id' => $saleId, 'sale_item_id' => $saleItemId,
                    'movement_value' => $movementValue, 'balance_value' => $endValue], 'created_at' => now()]);

            return app(PricingService::class)->syncProduct($p->id, $userId, 'stock:'.$movement->id);
        }, 3);
    }
}
