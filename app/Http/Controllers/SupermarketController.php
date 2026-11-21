<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Seller;
use App\Models\SupermarketCart;
use App\Services\CompanyOperations;
use App\Services\Supermarket;
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
        $cart = $cartId ? $service->owned($cartId, $actor->id)
            : SupermarketCart::where('user_id', $actor->id)->where('status', 'active')->first();
        $own = Seller::where('user_id', $actor->id)->first();
        $search = trim($request->validate(['q' => 'nullable|string|max:100'])['q'] ?? '');
        $products = $search === '' ? collect() : Product::where('active', true)->where('type', 'product')
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', '%'.addcslashes($search, '\\%_').'%')
                    ->orWhere('sku', $search)->orWhere('barcode', $search);
            })->orderBy('name')->limit(30)->get();
        return view('supermarket.index', [
            'cart' => $cart, 'carts' => SupermarketCart::where('user_id', $actor->id)->whereIn('status', ['active', 'suspended'])->latest()->get(),
            'products' => $products, 'customers' => Customer::orderBy('name')->get(),
            'sellers' => Seller::where('active', true)->orderBy('name')->get(), 'currentSalesperson' => $own,
            'selectedSalespersonId' => $own?->id, 'operationConfig' => app(CompanyOperations::class)->current(),
        ]);
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
        $service->add($cart, $input['version'], $input['code'], $input['quantity'], $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart);
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
        $service->checkout($cart, $input['version'], $input, $request->attributes->get('currentUser')->id);
        return $this->back($request, $cart)->with('success', 'Venda concluída. O comprovante está disponível neste atendimento.');
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
