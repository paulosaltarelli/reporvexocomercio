<?php

namespace App\Http\Controllers;

use App\Models\BusinessProfile;
use App\Services\ProductImages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class PublicCatalogController extends Controller
{
    private function products(): Builder
    {
        return DB::connection('tenant')->table('products')->where('active', true)->where('type', 'product');
    }

    public function index(Request $request, ProductImages $images)
    {
        $validator = Validator::make($request->query(), ['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1|max:100000']);
        abort_if($validator->fails(), 422);
        $search = trim($validator->validated()['q'] ?? '');
        $tenant = $request->attributes->get('tenant');
        $storeName = BusinessProfile::whereKey(1)->value('trade_name') ?: $tenant->name;
        $products = $this->products()
            ->when($search !== '', fn (Builder $query) => $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%'))
            ->orderBy('name')->orderBy('id')->simplePaginate(24, ['id', 'name', 'unit', 'sale_price'])
            ->withPath(route('storefront.index', ['company' => $tenant->slug], false))->appends(['q' => $search]);
        foreach ($products as $product) {
            $product->has_photo = $images->exists($tenant->id, $product->id);
        }
        return view('storefront.index', ['company' => $tenant->slug, 'storeName' => $storeName, 'products' => $products, 'search' => $search]);
    }

    public function image(Request $request, string $company, int $id, ProductImages $images)
    {
        abort_unless($this->products()->where('id', $id)->exists(), 404);
        $tenantId = $request->attributes->get('tenant')->id;
        $file = $images->catalogPhoto($tenantId, $id);
        abort_unless($file, 404);
        return response()->file($file, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff']);
    }
}
