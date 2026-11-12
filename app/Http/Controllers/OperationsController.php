<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Documents;
use App\Services\FinancialService;
use Illuminate\Http\Request;

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
        $d = $r->validate(['payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'paid' => 'required|boolean', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24']);
        $d['installments'] = $r->integer('installments', 1);
        $s = app(Documents::class)->invoice($id, $d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $s->id])->with('success', 'Venda gerada e estoque baixado. Não houve emissão fiscal.');
    }

    public function finance(Request $r)
    {
        $this->admin($r);
        $entries = FinancialEntry::with('payments', 'sale', 'purchase', 'supplier')->when($r->integer('sale'), fn ($q) => $q->where('sale_id', $r->integer('sale')))->orderByRaw('cancelled_at IS NOT NULL')->orderByRaw('paid_at IS NOT NULL')->orderBy('due_date')->paginate(20)->withQueryString();
        $balance = fn (string $direction) => FinancialEntry::where('direction', $direction)->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount-paid_amount),0) as balance')->value('balance');
        $month = Payment::whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->toDateString()]);

        return view('finance', ['entries' => $entries, 'suppliers' => Supplier::orderBy('name')->get(), 'payable' => $balance('out'), 'receivable' => $balance('in'), 'payments' => Payment::latest('paid_on')->latest('id')->limit(50)->get(), 'cashIn' => (clone $month)->where('direction', 'in')->sum('amount'), 'cashOut' => (clone $month)->where('direction', 'out')->sum('amount')]);
    }

    public function createEntry(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'direction' => 'required|in:in,out', 'description' => 'required|string|max:180', 'category' => 'required|string|max:100', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'due_date' => 'required|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24', 'supplier_id' => 'nullable|exists:tenant.suppliers,id']);
        $d['installments'] = $r->integer('installments', 1);
        app(FinancialService::class)->schedule($d, $r->attributes->get('currentUser')->id);

        return back()->with('success', 'Conta registrada. As parcelas somam exatamente o valor total.');
    }

    public function payEntry(Request $r, string $company, int $id)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'amount' => 'required|numeric|min:0.01|max:999999999.99|decimal:0,2', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice']);
        app(FinancialService::class)->pay($id, (string) $d['amount'], $d['paid_on'], $d['payment_method'], $r->attributes->get('currentUser')->id, $d['request_key']);

        return back()->with('success','Baixa registrada no histórico financeiro.');
    }
}
