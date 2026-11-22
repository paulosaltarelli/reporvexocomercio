<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CompanyOperations;
use App\Services\MeasureUnits;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class StockReplenishmentController extends Controller
{
    public function __invoke(Request $request, CompanyOperations $operations): View
    {
        $configuration = $operations->current();
        abort_unless($configuration['inventory'], 403, 'A gestão de estoque está desabilitada para esta empresa.');
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'sales' => 'nullable|in:recent,all',
        ]);
        $term = trim($filters['q'] ?? '');
        $recentOnly = ($filters['sales'] ?? 'recent') === 'recent';
        $now = now();
        $since = $now->copy()->subDays(59)->startOfDay();
        $products = Product::where('type', 'product')->where('active', true)->where('controls_stock', true)
            ->whereColumn('quantity', '<', 'minimum_stock')
            ->when($term !== '', function ($query) use ($term) {
                $pattern = '%'.addcslashes($term, '\\%_').'%';
                $query->where(fn ($match) => $match->where('name', 'like', $pattern)->orWhere('sku', 'like', $pattern));
            })
            ->when($recentOnly, fn ($query) => $query->whereExists(function (Builder $sale) use ($since, $now) {
                $sale->selectRaw('1')->from('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                    ->whereColumn('sale_items.product_id', 'products.id')
                    ->where(fn ($kind) => $kind->whereNull('sale_items.fiscal_kind')->orWhere('sale_items.fiscal_kind', 'product'))
                    ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$since, $now]);
            }))
            ->orderBy('name')->orderBy('id')
            ->paginate(25, ['id', 'name', 'sku', 'unit', 'type', 'minimum_stock', 'quantity'])
            ->withQueryString()
            ->through(function (Product $product) use ($configuration) {
                $required = bcsub($product->minimum_stock, $product->quantity, 3);
                $alternatives = MeasureUnits::options($product, $configuration, 'purchase');
                unset($alternatives[$product->unit]);
                $equivalents = [];
                foreach ($alternatives as $code => $factor) {
                    $quantity = bcdiv($required, $factor, 3);
                    // Show a converted quantity only if a purchase line can represent it exactly.
                    $exact = bccomp(bcmul($quantity, $factor, 9), $required, 9) === 0
                        && bccomp($quantity, '999999999', 3) <= 0 && bccomp($required, '999999999', 3) <= 0;
                    $equivalents[$code] = ['factor' => $factor, 'quantity' => $exact ? $quantity : null];
                }
                return ['product' => $product, 'required' => $required, 'equivalents' => $equivalents];
            });

        return view('stock-replenishment', compact('products', 'term', 'recentOnly', 'since', 'now'));
    }
}
