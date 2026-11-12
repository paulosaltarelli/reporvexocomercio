<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Documents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function documents()
    {
        return view('documents.index', ['documents' => Document::with('customer')->latest()->paginate(20)]);
    }

    public function form(Request $r)
    {
        abort_unless(in_array($r->query('type', 'quote'), ['quote', 'work_order']), 404);

        return view('documents.form', ['type' => $r->query('type', 'quote'), 'customers' => Customer::orderBy('name')->get(), 'products' => Product::where('active', true)->orderBy('name')->get(), 'services' => Service::where('active', true)->orderBy('name')->get()]);
    }

    public function create(Request $r, string $company)
    {
        $d = $r->validate(['type' => 'required|in:quote,work_order', 'request_key' => 'required|uuid', 'customer_id' => 'nullable|exists:tenant.customers,id', 'valid_until' => 'nullable|date', 'asset' => 'nullable|string|max:255', 'technician' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'items' => 'required|array|min:1|max:50', 'items.*.kind' => 'required|in:product,service', 'items.*.id' => 'required|integer|min:1', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2']);
        $doc = app(Documents::class)->create($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('document.show', ['company' => $company, 'id' => $doc->id])->with('success', 'Documento criado. Nenhum estoque foi movimentado.');
    }

    public function show(Request $r, string $company, int $id)
    {
        return view('documents.show', ['document' => Document::with('customer')->findOrFail($id)]);
    }

    public function transition(Request $r, string $company, int $id)
    {
        $d = $r->validate(['status' => 'required|in:approved,in_progress,completed,cancelled']);
        app(Documents::class)->transition($id, $d['status']);

        return back()->with('success', 'Etapa atualizada.');
    }

    public function invoice(Request $r, string $company, int $id)
    {
        $d = $r->validate(['payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'paid' => 'required|boolean', 'due_date' => 'nullable|required_if:paid,0|date']);
        $s = app(Documents::class)->invoice($id, $d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $s->id])->with('success', 'Venda gerada e estoque baixado. Não houve emissão fiscal.');
    }

    public function finance(Request $r)
    {
        $this->admin($r);

        return view('finance', ['entries' => FinancialEntry::latest()->paginate(20), 'suppliers' => Supplier::orderBy('name')->get(), 'pendingSales' => Sale::with('customer')->where('status', 'completed')->where('paid', false)->orderBy('due_date')->get(), 'payable' => FinancialEntry::where('direction', 'out')->whereNull('paid_at')->sum('amount'), 'otherReceivable' => FinancialEntry::where('direction', 'in')->whereNull('paid_at')->sum('amount')]);
    }

    public function createEntry(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'direction' => 'required|in:in,out', 'description' => 'required|string|max:180', 'category' => 'required|string|max:100', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'due_date' => 'required|date', 'supplier_id' => 'nullable|exists:tenant.suppliers,id']);
        $d['user_id'] = $r->attributes->get('currentUser')->id;
        FinancialEntry::firstOrCreate(['request_key' => $d['request_key']], $d);

        return back()->with('success', 'Conta registrada.');
    }

    public function payEntry(Request $r, string $company, int $id)
    {
        $this->admin($r);
        DB::connection('tenant')->transaction(function () use ($id) {
            $e = FinancialEntry::lockForUpdate()->findOrFail($id);
            if (! $e->paid_at) {
                $e->update(['paid_at' => now()]);
            }
        });

        return back()->with('success','Liquidação registrada.');
    }
}
