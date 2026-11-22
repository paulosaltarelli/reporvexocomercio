<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\PurchaseService;
use App\Services\PurchaseInvoice;
use App\Models\OperationRecord;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    private function admin(Request $r): void
    {
        app(\App\Access\AccessProfiles::class)->authorize($r);
    }

    public function index(Request $r)
    {
        $this->admin($r);

        return view('purchases.index', ['purchases' => Purchase::with('supplier')->latest()->paginate(20)]);
    }

    public function form(Request $r)
    {
        $this->admin($r);

        return view('purchases.form', ['suppliers' => Supplier::orderBy('name')->get(), 'products' => Product::where('active', true)->where('type','product')->orderBy('name')->get()]);
    }

    public function previewXml(Request $r, string $company)
    {
        $this->admin($r);
        abort_unless(PurchaseInvoice::enabled(), 404);
        $r->validate(['invoice_xml'=>'required|file|max:2048']);
        $preview = \App\Services\PurchaseInvoiceXml::preview(
            $r->file('invoice_xml')->getContent(),
            (string)\App\Models\BusinessProfile::find(1)?->document
        );
        $supplier = Supplier::select('id','document')->get()->first(fn ($s) =>
            strtoupper(preg_replace('/[^a-zA-Z0-9]/','',(string)$s->document)) === $preview['issuer_document']
        );
        return redirect()->route('purchase.new',['company'=>$company])->withInput([
            'invoice'=>$preview['invoice'],'items'=>$preview['items'],'supplier_id'=>$supplier?->id,
            'paid'=>'0','source_issuer'=>$preview['issuer_name'].' · '.$preview['issuer_document'],
        ]);
    }

    public function create(Request $r, string $company)
    {
        $this->admin($r);
        PurchaseInvoice::guardProductInvoiceInputs($r->all());
        $items = $r->input('items');
        if (is_array($items)) {
            foreach ($items as $index => &$item) {
                if (!is_array($item) || !isset($item['fiscal_detail'])) continue;
                $raw = $item['fiscal_detail'];
                $detail = is_string($raw) && strlen($raw)<=8192 ? json_decode($raw,true,8) : null;
                if (!is_array($detail) || array_diff(array_keys($detail),['adjustments','taxes'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['items.'.$index=>'Valores fiscais do item inválidos.']);
                }
                unset($item['fiscal_detail']);
                $item = array_merge($item,$detail);
            }
            unset($item);
            $r->merge(['items'=>$items]);
        }
        $d = $r->validate(PurchaseInvoice::rules() + ['request_key' => 'required|uuid', 'supplier_id' => 'required|exists:tenant.suppliers,id', 'reference' => 'nullable|string|max:100', 'purchased_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'notes' => 'nullable|string|max:1000', 'paid' => 'required|boolean', 'installments' => 'nullable|integer|min:1|max:24', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice','payment_option'=>'nullable|string|max:60', 'items' => 'required|array|min:1|max:50', 'items.*.product_id' => 'required|integer|exists:tenant.products,id', 'items.*.measure_unit'=>'nullable|string|max:10','items.*.factor_seen'=>'nullable|string|max:20','items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_cost' => 'required|numeric|min:0|max:999999999.9999|decimal:0,4']);
        $d['installments'] = $r->integer('installments', 1);
        $d['due_date'] = $d['due_date'] ?? now()->toDateString();
        $p = app(PurchaseService::class)->receive($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('purchase.show', ['company' => $company, 'id' => $p->id])->with('success', 'Compra confirmada: estoque, custo médio e contas a pagar atualizados.');
    }

    public function show(Request $r, string $company, int $id)
    {
        $this->admin($r);

        $invoice = PurchaseInvoice::enabled()
            ? OperationRecord::where('action','purchase_invoice')->where('after->purchase_id',$id)->first()?->after : null;
        return view('purchases.show', ['invoice' => $invoice, 'purchase' => Purchase::with('supplier', 'items')->findOrFail($id)]);
    }
}
