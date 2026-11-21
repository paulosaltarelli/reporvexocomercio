<?php

namespace App\Http\Controllers;

use App\Models\{Product, RestaurantModifierGroup, RestaurantProductConfig};
use App\Services\{CompanyOperations, Decimal, InstallerAccess, OperationalModes, RestaurantCatalog};
use Illuminate\Http\Request;

final class RestaurantCatalogController extends Controller
{
    private function actor(Request $request): int
    {
        app(OperationalModes::class)->require('restaurant');
        $id = (int)$request->attributes->get('currentUser')->id;
        InstallerAccess::authorizedActor($id, 'restaurant.catalog.manage');
        return $id;
    }

    public function index(Request $request)
    {
        $this->actor($request);
        $query = $request->validate(['q' => 'nullable|string|max:100'])['q'] ?? '';
        $products = Product::where('type', 'product')->where('active', true)
            ->when($query !== '', fn ($builder) => $builder->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($query, '\\%_').'%')->orWhere('sku', $query)))
            ->orderBy('name')->paginate(30)->withQueryString();
        return view('restaurant-catalog.index', ['products' => $products, 'search' => $query,
            'configs' => RestaurantProductConfig::whereIn('product_id', $products->modelKeys())->get()->keyBy('product_id')]);
    }

    public function edit(Request $request, string $company, int $product)
    {
        $this->actor($request);
        $product = Product::where('type', 'product')->findOrFail($product);
        $config = RestaurantProductConfig::where('product_id', $product->id)->first()
            ?? new RestaurantProductConfig(['revision' => 0, 'station' => 'kitchen', 'stock_mode' => 'product', 'recipe' => ['ingredients' => []]]);
        $ingredients = Product::where('type', 'product')->where('controls_stock', true)->where('id', '<>', $product->id)->orderBy('name')->get();
        $cost = null;
        if ($request->attributes->get('currentUser')->allows('costs.view')) {
            $cost = '0.0000000';
            foreach ($config->recipe['ingredients'] ?? [] as $row) {
                $ingredient = $ingredients->firstWhere('id', $row['product_id']);
                if ($ingredient) $cost = bcadd($cost, bcmul($row['quantity'], $ingredient->pricingCost(), 7), 7);
            }
            $cost = Decimal::round($cost, 4);
        }
        return view('restaurant-catalog.edit', ['product' => $product, 'config' => $config,
            'groups' => RestaurantModifierGroup::where('product_id', $product->id)->with('options')->orderBy('id')->get(),
            'ingredients' => $ingredients, 'catalogConfiguration' => app(CompanyOperations::class)->current(), 'estimatedCost' => $cost]);
    }

    public function save(Request $request, string $company, int $product, RestaurantCatalog $catalog)
    {
        $actor = $this->actor($request);
        if (!$request->boolean('catalog_complete')) {
            // PHP may truncate a large form before its final field. Never reuse that partial draft.
            $request->replace([]);
            throw \Illuminate\Validation\ValidationException::withMessages(['catalog_complete' => 'O formulário chegou incompleto. Reabra o cardápio antes de salvar novamente.']);
        }
        $catalog->save($product, $request->all(), $actor);
        return redirect()->route('restaurant.catalog.edit', ['company' => $company, 'product' => $product])
            ->with('success', 'Cardápio atualizado. Pedidos já lançados mantêm os adicionais e a ficha registrados.');
    }
}
