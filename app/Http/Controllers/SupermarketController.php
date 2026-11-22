<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SupermarketCart;
use App\Models\SupermarketShift;
use App\Services\CompanyOperations;
use App\Services\Supermarket;
use App\Services\SupermarketShifts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class SupermarketController extends Controller
{
    public function index(Request $request, Supermarket $service)
    {
        return $this->screen($request, $service);
    }

    public function show(Request $request, string $company, int $cart, Supermarket $service)
    {
        return $this->screen($request, $service, $cart);
    }

    private function screen(Request $request, Supermarket $service, ?int $cartId = null)
    {
        $actor = $request->attributes->get('currentUser');
        $service->actor($actor->id);
        $shifts = app(SupermarketShifts::class);
        $shift = $shifts->current($actor->id);
        if (!$shift) {
            $cart = $cartId ? $service->owned($cartId, $actor->id) : null;
            $lastShift = SupermarketShift::where('user_id', $actor->id)->whereNotNull('closed_at')
                ->orderByDesc('closed_at')->orderByDesc('id')->first();

            return view('supermarket.index', compact('shift', 'cart', 'lastShift'));
        }
        $cart = $cartId ? $service->owned($cartId, $actor->id)
            : SupermarketCart::where('user_id', $actor->id)->where('status', 'active')->first();
        $own = Seller::where('user_id', $actor->id)->first();
        $searchInput = $request->validate(['q' => 'nullable|string|max:100', 'search_quantity' => 'nullable|string|max:24']);
        $search = trim($searchInput['q'] ?? '');
        $searchQuantity = \App\Services\Decimal::input($searchInput['search_quantity'] ?? '1', 3, 'search_quantity', '0.001', '999999999.999');
        $products = $search === '' ? collect() : $this->matchingProducts($search)->limit(30)->get();
        return view('supermarket.index', [
            'shift' => $shift, 'expectedBalance' => $shifts->expected($shift),
            'cart' => $cart, 'carts' => SupermarketCart::where('user_id', $actor->id)->whereIn('status', ['active', 'suspended'])->latest()->get(),
            'products' => $products, 'searchQuantity' => $searchQuantity, 'customers' => Customer::orderBy('name')->get(),
            'approvals'=>$cart ? \App\Models\SupermarketApproval::where('cart_id',$cart->id)->where('requested_by',$actor->id)->latest()->limit(20)->get() : collect(),
            'sellers' => Seller::where('active', true)->orderBy('name')->get(), 'currentSalesperson' => $own,
            'selectedSalespersonId' => $own?->id, 'operationConfig' => app(CompanyOperations::class)->current(),
        ]);
    }

    private function matchingProducts(string $term): Builder
    {
        $literal = addcslashes($term, '\\%_');
        return Product::where('active', true)->where('type', 'product')
            ->where(function ($query) use ($term, $literal) {
                $query->where('name', 'like', '%'.$literal.'%')->orWhere('sku', $term)->orWhere('barcode', $term);
            })
            ->orderByRaw('CASE WHEN sku = ? OR barcode = ? THEN 0 WHEN name LIKE ? THEN 1 ELSE 2 END', [$term, $term, $literal.'%'])
            ->orderBy('name')->orderBy('id');
    }

    public function products(Request $request, string $company, int $cart, Supermarket $service)
    {
        $userId = $request->attributes->get('currentUser')->id;
        $service->actor($userId);
        abort_unless($service->owned($cart, $userId)->status === 'active', 409);
        $input = $request->validate(['q' => 'required|string|min:2|max:80']);
        $products = $this->matchingProducts($input['q'])->select(['name', 'sku', 'barcode', 'unit', 'sale_price'])
            ->selectRaw('CASE WHEN sku = ? OR barcode = ? THEN 1 ELSE 0 END AS exact_code', [$input['q'], $input['q']])
            ->limit(13)->get();

        return response()->json(['products' => $products->take(12)->values(), 'more' => $products->count() > 12])
            ->header('Cache-Control', 'private, no-store');
    }

    public function lookup(Request $request, Supermarket $service)
    {
        $service->actor($request->attributes->get('currentUser')->id);
        $input = $request->validate(['code' => 'required|string|max:80']);
        $scan = $service->scan($input['code']);
        $product = $scan['product'];
        return response()->json($product->only(['id', 'name', 'sku', 'barcode', 'unit', 'sale_price']) + [
            'scanned_quantity' => $scan['quantity'], 'weighted_label' => $scan['label'],
        ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, Supermarket $service)
    {
        $input = $request->validate(['request_key' => 'required|uuid']);
        $cart = $service->start($input['request_key'], $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart->id);
    }

    public function addItem(Request $request, string $company, int $cart, Supermarket $service)
    {
        $input = $request->validate(['version' => 'required|integer|min:0', 'code' => 'required|string|max:80', 'quantity' => 'required|string|max:24']);
        $result = $service->enter($cart, $input['version'], $input['code'], $input['quantity'], $request->attributes->get('currentUser')->id);
        if (isset($result['search'])) {
            return redirect()->route('supermarket.show', ['company' => $company, 'cart' => $cart,
                'q' => $result['search'], 'search_quantity' => $result['quantity']]);
        }
        $updated = $result['cart'];
        return $this->back($request, $cart)->with('last_supermarket_scan', [
            'cart_id' => $updated->id, 'line_key' => $updated->lastScannedLineKey,
        ]);
    }

    public function updateItem(Request $request, string $company, int $cart, string $line, Supermarket $service)
    {
        $input = $request->validate(['version' => 'required|integer|min:0', 'quantity' => 'required|string|max:24', 'reason' => 'nullable|string|min:3|max:1000']);
        $service->changeItem($cart, $input['version'], $line, $input['quantity'], $input['reason'] ?? null, $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart);
    }

    public function removeItem(Request $request, string $company, int $cart, string $line, Supermarket $service)
    {
        $input = $request->validate(['version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:1000']);
        $service->changeItem($cart, $input['version'], $line, null, $input['reason'], $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart);
    }

    public function suspend(Request $request, string $company, int $cart, Supermarket $service)
    {
        return $this->transition($request, $cart, $service, 'suspended');
    }

    public function resume(Request $request, string $company, int $cart, Supermarket $service)
    {
        return $this->transition($request, $cart, $service, 'active');
    }

    public function cancel(Request $request, string $company, int $cart, Supermarket $service)
    {
        return $this->transition($request, $cart, $service, 'cancelled');
    }

    private function transition(Request $request, int $cart, Supermarket $service, string $status)
    {
        $input = $request->validate(['version' => 'required|integer|min:0',
            'reason' => ($status === 'cancelled' ? 'required' : 'nullable').'|string|min:3|max:1000']);
        $service->transition($cart, $input['version'], $status, $input['reason'] ?? null, $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart);
    }

    public function checkout(Request $request, string $company, int $cart, Supermarket $service)
    {
        $input = $request->validate(array_merge(\App\Services\SalePayments::rules(), [
            'version' => 'required|integer|min:0', 'customer_id' => 'nullable|integer|exists:tenant.customers,id',
            'salesperson_id' => 'nullable|integer|exists:tenant.sellers,id', 'payment_option' => 'nullable|string|max:60',
            'payment_method' => 'required_without:payments|in:cash,pix,credit,debit,transfer,invoice',
            'paid' => 'required_without:payments|boolean', 'installments' => 'nullable|integer|min:1|max:24',
            'due_date' => ($request->has('payments') ? 'nullable|date_format:Y-m-d' : 'nullable|required_if:paid,0|date_format:Y-m-d'),
        ]));
        $sale=$service->checkout($cart, $input['version'], $input, $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart)->with('success', 'Venda concluída. O comprovante está disponível neste atendimento.')->with('stock_warning',app(\App\Services\Inventory::class)->saleWarning($sale->id));
    }

    public function receipt(Request $request, string $company, int $cart, Supermarket $service)
    {
        $actor = $request->attributes->get('currentUser');
        $service->actor($actor->id, 'supermarket.checkout');
        $record = $service->owned($cart, $actor->id);
        abort_unless($record->status === 'completed' && $record->sale_id, 404);
        $sale = \App\Models\Sale::whereKey($record->sale_id)->where('user_id', $actor->id)
            ->where('request_key', $record->request_key)->firstOrFail();

        return app(\App\Services\OperationalReceipt::class)->render($sale,
            route('supermarket.show', ['company' => $company, 'cart' => $record->id]));
    }

    private function back(Request $request, int $cart)
    {
        return redirect()->route('supermarket.show', ['company' => $request->route('company'), 'cart' => $cart]);
    }
}
