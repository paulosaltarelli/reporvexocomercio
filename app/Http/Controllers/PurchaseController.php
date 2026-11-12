<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function index(Request $r)
    {
        $this->admin($r);

        return view('purchases.index', ['purchases' => Purchase::with('supplier')->latest()->paginate(20)]);
    }

    public function form(Request $r)
    {
        $this->admin($r);

        return view('purchases.form', ['suppliers' => Supplier::orderBy('name')->get(), 'products' => Product::where('active', true)->orderBy('name')->get()]);
    }

    public function create(Request $r, string $company)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'supplier_id' => 'required|exists:tenant.suppliers,id', 'reference' => 'nullable|string|max:100', 'purchased_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'notes' => 'nullable|string|max:1000', 'paid' => 'required|boolean', 'installments' => 'nullable|integer|min:1|max:24', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|exists:tenant.products,id', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_cost' => 'required|numeric|min:0|max:999999999.9999|decimal:0,4']);
        $d['installments'] = $r->integer('installments', 1);
        $d['due_date'] = $d['due_date'] ?? now()->toDateString();
        $p = app(PurchaseService::class)->receive($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('purchase.show', ['company' => $company, 'id' => $p->id])->with('success', 'Compra confirmada: estoque, custo médio e contas a pagar atualizados.');
    }

    public function show(Request $r, string $company, int $id)
    {
        $this->admin($r);

        return view('purchases.show', ['purchase' => Purchase::with('supplier', 'items')->findOrFail($id)]);
    }
}
