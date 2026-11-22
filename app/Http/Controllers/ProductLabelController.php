<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductLabelBarcode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ProductLabelController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        [$quantities, $format] = $this->selection($request);
        $chosen = $this->products()->whereKey(array_keys($quantities))->get()->keyBy('id');
        $query = trim($data['q'] ?? '');
        $products = $this->products()->when($query !== '', function ($builder) use ($query) {
            $like = '%'.addcslashes($query, '\\%_').'%';
            $builder->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like));
        })->orderBy('name')->orderBy('id')->paginate(20);
        $otherSelected = $chosen->except($products->pluck('id')->all());

        return view('products.labels', compact('products', 'quantities', 'otherSelected', 'format', 'query'));
    }

    public function preview(Request $request, ProductLabelBarcode $barcode)
    {
        [$quantities, $format] = $this->selection($request);
        if (!$quantities) {
            throw ValidationException::withMessages(['qty' => 'Informe a quantidade de etiquetas de pelo menos um produto.']);
        }
        $products = $this->products()
            ->selectRaw("NOT EXISTS (SELECT 1 FROM products other WHERE other.id <> products.id AND other.active = 1 AND other.type = 'product' AND other.sku = products.barcode) AS barcode_unique")
            ->selectRaw("NOT EXISTS (SELECT 1 FROM products other WHERE other.id <> products.id AND other.active = 1 AND other.type = 'product' AND other.barcode = products.sku) AS sku_unique")
            ->whereKey(array_keys($quantities))->get()->keyBy('id');
        if ($products->count() !== count($quantities)) {
            throw ValidationException::withMessages(['qty' => 'Um dos produtos selecionados está indisponível. Atualize a seleção.']);
        }
        $labels = [];
        foreach ($quantities as $id => $copies) {
            $product = $products->get($id);
            $labels[] = ['product' => $product, 'copies' => $copies, 'barcode' => $barcode->forProduct($product)];
        }
        return response()->view('printing.product-labels', compact('labels', 'format', 'quantities'))
            ->header('Cache-Control', 'private, no-store');
    }

    private function products(): Builder
    {
        return Product::where('active', true)->where('type', 'product')
            ->select(['id', 'name', 'sku', 'barcode', 'sale_price', 'unit']);
    }

    private function selection(Request $request): array
    {
        $data = $request->validate([
            'format' => 'nullable|in:a4,roll',
            'qty' => 'nullable|array|max:70',
            'qty.*' => 'nullable|integer|min:0|max:200',
        ]);
        $quantities = [];
        foreach ($data['qty'] ?? [] as $id => $quantity) {
            if (!is_int($id) || $id < 1) {
                throw ValidationException::withMessages(['qty' => 'Selecione produtos do catálogo da loja.']);
            }
            if ((int) $quantity > 0) $quantities[$id] = (int) $quantity;
        }
        if (count($quantities) > 50 || array_sum($quantities) > 200) {
            throw ValidationException::withMessages(['qty' => 'Imprima até 200 etiquetas de até 50 produtos por lote.']);
        }
        return [$quantities, $data['format'] ?? 'a4'];
    }
}
