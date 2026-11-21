<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\OperationalModes;
use App\Services\ProductImages;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProductImageController extends Controller
{
    public function show(Request $request, string $company, int $id, ProductImages $images)
    {
        $user = $request->attributes->get('currentUser');
        $modes = app(OperationalModes::class);
        abort_unless($user && ($user->allows('products.view')
            || ($modes->enabled('restaurant') && $user->allows('restaurant.use'))
            || ($modes->enabled('supermarket') && $user->allows('supermarket.use'))), 403);
        Product::when(!$user->allows('products.view'), fn($query)=>$query->where('active', true))->findOrFail($id);
        $tenantId = $request->attributes->get('tenant')->id;
        try { $file = $images->file($tenantId, $id); } catch (\RuntimeException|\JsonException $error) { abort(404); }
        abort_unless($file, 404);
        $response = response()->file($file, ['Content-Type'=>'image/png', 'X-Content-Type-Options'=>'nosniff']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }

    public function sheet(Request $request, string $company, string $digest, ProductImages $images)
    {
        $user = $request->attributes->get('currentUser');
        $modes = app(OperationalModes::class);
        abort_unless($user && ($user->allows('products.view')
            || ($modes->enabled('restaurant') && $user->allows('restaurant.use'))
            || ($modes->enabled('supermarket') && $user->allows('supermarket.use'))), 403);
        try {
            $tenantId = $request->attributes->get('tenant')->id;
            $productIds = $images->sheetProductIds($tenantId, $digest);
            abort_unless(Product::whereIn('id',$productIds)->when(!$user->allows('products.view'), fn($query)=>$query->where('active',true))->exists(),404);
            $file = $images->sheetFile($tenantId, $digest);
        }
        catch (\RuntimeException|\JsonException $error) { abort(404); }
        abort_unless($file, 404);
        $response = response()->file($file, ['Content-Type'=>'image/png', 'X-Content-Type-Options'=>'nosniff']);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-cache');
        $response->setEtag($digest);
        $response->isNotModified($request);
        return $response;
    }

    public function save(Request $request, string $company, int $id, ProductImages $images)
    {
        abort_unless($request->attributes->get('currentUser')->allows('products.manage'), 403);
        Product::findOrFail($id);
        $request->validate([
            'product_image'=>'nullable|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:min_width=48,min_height=48,max_width=2048,max_height=2048',
            'remove_product_image'=>'nullable|boolean',
        ]);
        $file = $request->file('product_image');
        $remove = $request->boolean('remove_product_image');
        if (($file && $remove) || (!$file && !$remove)) throw ValidationException::withMessages(['product_image'=>'Escolha uma nova imagem ou marque a remoção da imagem atual.']);
        $tenantId = $request->attributes->get('tenant')->id;
        if ($file) $images->store($tenantId, $id, $file);
        else $images->remove($tenantId, $id);
        return redirect()->route('product.edit', ['company'=>$company, 'id'=>$id])->with('success', 'Imagem do produto atualizada.');
    }
}
