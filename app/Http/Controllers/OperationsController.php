<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Document;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\Seller;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Documents;
use App\Services\CommercialRules;
use App\Services\SellerAssignment;
use App\Services\SellerFormContext;
use App\Services\FinancialService;
use Illuminate\Http\Request;

class OperationsController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function documents(Request $request)
    {
        $data = $request->validate([
            'salesperson_id' => 'nullable|integer|exists:tenant.sellers,id',
            'type' => 'nullable|in:quote,work_order',
            'status' => 'nullable|in:draft,approved,in_progress,completed,invoiced,cancelled',
            'q' => 'nullable|string|max:100',
        ]);
        $sellerId = isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null;
        $typeFilter = $data['type'] ?? null;
        $statusFilter = $data['status'] ?? null;
        $search = trim($data['q'] ?? '');
        $documents = Document::with('customer')
            ->when($sellerId, fn ($q) => $q->where('salesperson_id', $sellerId))
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $needle = '%'.addcslashes($search, '\\%_').'%';
                    $q->where('asset', 'like', $needle)->orWhere('technician', 'like', $needle)->orWhere('notes', 'like', $needle)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $needle)->orWhere('document', 'like', $needle)->orWhere('phone', 'like', $needle));
                    if (ctype_digit($search) && strlen($search) <= 18) { $q->orWhere('id', (int) $search); }
                });
            })->latest('id')->paginate(20)->withQueryString();
        return view('documents.index', compact('documents', 'sellerId', 'typeFilter', 'statusFilter', 'search') + ['sellers' => Seller::orderBy('name')->get()]);
    }

    public function form(Request $r)
    {
        abort_unless(in_array($r->query('type','quote'),['quote','work_order']),404);
        $data=$r->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','customer_id'=>'nullable|integer|exists:tenant.customers,id']);
        $context=app(SellerFormContext::class)->forUser($r->attributes->get('currentUser'),isset($data['salesperson_id'])?(int)$data['salesperson_id']:null);
        return view('documents.form',array_merge($context,['type'=>$r->query('type','quote'),
            'customers'=>Customer::orderBy('name')->get(),'products'=>Product::with('group')->where('active',true)->orderBy('name')->get(),
            'services'=>collect(),'selectedCustomerId'=>$data['customer_id']??null,'document'=>null,'initialItems'=>[]]));
    }

    public function create(Request $r, string $company)
    {
        $d = $r->validate(['type' => 'required|in:quote,work_order', 'request_key' => 'required|uuid', 'customer_id' => 'nullable|exists:tenant.customers,id', 'valid_until' => 'nullable|date', 'asset' => 'nullable|string|max:255', 'technician' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000', 'seller_id'=>'nullable|integer|exists:tenant.users,id','salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','items' => ($r->input('type') === 'work_order' ? 'sometimes|array|max:50' : 'required|array|min:1|max:50'), 'items.*.kind' => 'required|in:product,service', 'items.*.id' => 'required|integer|min:1', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'items.*.discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','items.*.catalog_price_seen'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2']);
        $d['items'] ??= [];
        $doc = app(Documents::class)->create($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('document.show', ['company' => $company, 'id' => $doc->id])->with('success', 'Documento criado. Nenhum estoque foi movimentado.');
    }

    public function edit(Request $r, string $company, int $id, Documents $documents)
    {
        $document = Document::findOrFail($id);
        abort_unless($document->type === 'work_order' && $document->status === 'draft' && ! $document->sale_id, 409, 'Somente uma OS aberta pode ser editada.');
        $current = Seller::where('user_id', $r->attributes->get('currentUser')->id)->first();
        return view('documents.form', [
            'document' => $document, 'type' => 'work_order', 'editHash' => $documents->editHash($document),
            'initialItems' => collect($document->items)->map(fn ($item) => \Illuminate\Support\Arr::only($item, ['kind', 'id', 'quantity', 'unit_price', 'offered_unit_price', 'discount_percent', 'explicit_discount_percent']))->all(), 'selectedCustomerId' => $document->customer_id,
            'customers' => Customer::orderBy('name')->get(), 'products' => Product::with('group')->where('active', true)->orderBy('name')->get(),
            'services' => collect(), 'sellers' => collect(), 'currentSalesperson' => $current, 'selectedSalespersonId' => $current?->id,
        ]);
    }

    public function update(Request $r, string $company, int $id, Documents $documents)
    {
        // Domain validates every editable field again and rejects seller/type overrides.
        $document = $documents->updateDraft($id, $r->all(), $r->attributes->get('currentUser')->id);
        return redirect()->route('document.show', ['company' => $company, 'id' => $document->id])->with('success', 'OS atualizada. Estoque e financeiro não foram movimentados.');
    }

    public function show(Request $r, string $company, int $id)
    {
        return view('documents.show', ['document' => Document::with('customer','creator')->findOrFail($id),'sellers'=>Seller::where('active',true)->orderBy('name')->get(),'assignments'=>Document::findOrFail($id)->assignments()->orderBy('id')->get()]);
    }

    public function assignSeller(Request $request, string $company, int $id)
    {
        $this->admin($request);
        $request->merge(['reason'=>trim((string)$request->input('reason',''))]);
        $data=$request->validate(['salesperson_id'=>'required|integer|exists:tenant.sellers,id','reason'=>'required|string|min:3|max:1000']);
        app(SellerAssignment::class)->assign($id,$request->attributes->get('currentUser')->id,(int)$data['salesperson_id'],$data['reason']);

        return redirect()->route('document.show',['company'=>$company,'id'=>$id])->with('success','Vendedor vinculado. O motivo e o responsável pela alteração foram registrados.');
    }

    public function transition(Request $r, string $company, int $id)
    {
        $d = $r->validate(['status' => 'required|in:approved,in_progress,completed,cancelled']);
        app(Documents::class)->transition($id, $d['status']);

        return back()->with('success', 'Etapa atualizada.');
    }

    public function invoice(Request $r, string $company, int $id)
    {
        $d = $r->validate(['payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'paid' => 'required|boolean', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24','seller_id'=>'nullable|integer|exists:tenant.users,id','salesperson_id'=>'nullable|integer|exists:tenant.sellers,id']);
        $d['installments'] = $r->integer('installments', 1);
        $s = app(Documents::class)->invoice($id, $d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $s->id])->with('success', 'Venda gerada e estoque baixado. Não houve emissão fiscal.');
    }

    public function finance(Request $r)
    {
        $this->admin($r);
        $entries = FinancialEntry::with('payments', 'sale', 'purchase', 'supplier')->when($r->integer('sale'), fn ($q) => $q->where('sale_id', $r->integer('sale')))->orderByRaw('cancelled_at IS NOT NULL')->orderByRaw('paid_at IS NOT NULL')->orderBy('due_date')->paginate(20)->withQueryString();
        $balance = fn (string $direction) => FinancialEntry::where('direction', $direction)->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount-paid_amount),0) as outstanding_total')->value('outstanding_total');
        $month = Payment::whereBetween('paid_on', [now()->startOfMonth()->toDateString(), now()->toDateString()]);

        return view('finance', ['entries' => $entries, 'suppliers' => Supplier::orderBy('name')->get(), 'payable' => $balance('out'), 'receivable' => $balance('in'), 'payments' => Payment::latest('paid_on')->latest('id')->limit(50)->get(), 'cashIn' => (clone $month)->where('direction', 'in')->sum('amount'), 'cashOut' => (clone $month)->where('direction', 'out')->sum('amount')]);
    }

    public function createEntry(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'direction' => 'required|in:in,out', 'description' => 'required|string|max:180', 'category' => 'required|string|max:100', 'amount' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2', 'due_date' => 'required|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24', 'supplier_id' => 'nullable|exists:tenant.suppliers,id']);
        $d['installments'] = $r->integer('installments', 1);
        app(FinancialService::class)->schedule($d, $r->attributes->get('currentUser')->id);

        return back()->with('success', 'Conta registrada. As parcelas somam exatamente o valor total.');
    }

    public function payEntry(Request $r, string $company, int $id)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'amount' => 'required|numeric|min:0.01|max:999999999999.99|decimal:0,2', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice']);
        app(FinancialService::class)->pay($id, (string) $d['amount'], $d['paid_on'], $d['payment_method'], $r->attributes->get('currentUser')->id, $d['request_key']);

        return back()->with('success', 'Baixa registrada no histórico financeiro.');
    }
}
