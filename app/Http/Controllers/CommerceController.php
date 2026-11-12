<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Checkout;
use App\Services\FinancialService;
use App\Services\Inventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Ramsey\Uuid\Uuid;

class CommerceController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function dashboard()
    {
        $sales = Sale::where('status', 'completed');

        return view('dashboard', [
            'revenue' => (clone $sales)->sum('total'), 'cost' => (clone $sales)->sum('cost_total'),
            'receivable' => FinancialEntry::where('direction', 'in')->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount-paid_amount),0) as balance')->value('balance'),
            'stockValue' => Product::selectRaw('COALESCE(SUM(quantity * average_cost),0) as value')->value('value'),
            'lowStock' => Product::where('active', true)->whereColumn('quantity', '<=', 'minimum_stock')->orderBy('name')->limit(8)->get(),
            'recent' => Sale::with('customer')->latest()->limit(8)->get(),
        ]);
    }

    public function products(Request $r)
    {
        return view('products.index', ['products' => Product::with('group')->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('sku', 'like', '%'.$r->q.'%')->orWhere('barcode', 'like', '%'.$r->q.'%')))->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function productForm(Request $r, string $company, ?int $id = null)
    {
        $this->admin($r);

        return view('products.form', ['product' => $id ? Product::findOrFail($id) : new Product(['unit' => 'UN', 'sale_price' => 0, 'minimum_stock' => 0, 'active' => true]), 'groups' => ProductGroup::orderBy('name')->get()]);
    }

    public function saveProduct(Request $r, string $company, ?int $id = null)
    {
        $this->admin($r);
        $p = $id ? Product::findOrFail($id) : new Product;
        $d = $r->validate(['name' => 'required|string|max:180', 'sku' => ['required', 'string', 'max:80', Rule::unique('tenant.products', 'sku')->ignore($p->id)], 'barcode' => ['nullable', 'string', 'max:80', Rule::unique('tenant.products', 'barcode')->ignore($p->id)], 'product_group_id' => 'required|exists:tenant.product_groups,id', 'unit' => 'required|in:UN,KG,M,L,CX,PC,PAR', 'margin' => 'nullable|numeric|min:0|max:99.99|decimal:0,2', 'sale_price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2', 'minimum_stock' => 'required|numeric|min:0|max:999999999|decimal:0,3', 'ncm' => 'nullable|digits:8', 'origin' => 'nullable|in:0,1,2,3,4,5,6,7,8', 'active' => 'nullable|boolean']);
        $d['active'] = $r->boolean('active');
        $p->fill($d)->save();

        return redirect()->route('products', ['company' => $company])->with('success', 'Produto salvo.');
    }

    public function catalog(Request $r, string $company, string $kind)
    {
        [$model,$title,$fields] = $this->catalogSpec($kind);

        return view('catalog', ['rows' => $model::orderBy('name')->paginate(20), 'title' => $title, 'fields' => $fields, 'kind' => $kind, 'editing' => $r->integer('edit') ? $model::findOrFail($r->integer('edit')) : null]);
    }

    private function catalogSpec(string $kind): array
    {
        return match ($kind) {
            'groups' => [ProductGroup::class, 'Grupos de produtos', ['name' => 'Nome', 'margin' => 'Margem padrão (%)']],
            'suppliers' => [Supplier::class, 'Fornecedores', ['name' => 'Nome / razão social', 'document' => 'CPF / CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'address' => 'Endereço']],
            'customers' => [Customer::class, 'Clientes', ['name' => 'Nome / razão social', 'document' => 'CPF / CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'address' => 'Endereço']],
            'services' => [Service::class, 'Serviços', ['name' => 'Descrição do serviço', 'price' => 'Preço (R$)', 'cost' => 'Custo (R$)', 'municipal_service_code' => 'Código municipal (futuro fiscal)', 'iss_rate' => 'Alíquota ISS (%)']],
            default => abort(404),
        };
    }

    public function saveCatalog(Request $r, string $company, string $kind)
    {
        $this->admin($r);
        [$model] = $this->catalogSpec($kind);
        $row = $r->integer('id') ? $model::findOrFail($r->integer('id')) : new $model;
        $rules = match ($kind) {
            'groups' => ['name' => ['required', 'string', 'max:180', Rule::unique('tenant.product_groups', 'name')->ignore($row->id)], 'margin' => 'required|numeric|min:0|max:99.99|decimal:0,2'],
            'customers','suppliers' => ['name' => 'required|string|max:180', 'document' => 'nullable|string|max:20', 'email' => 'nullable|email|max:180', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000'],
            'services' => ['name' => 'required|string|max:180', 'price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2', 'cost' => 'required|numeric|min:0|max:999999999.99|decimal:0,2', 'municipal_service_code' => 'nullable|string|max:30', 'iss_rate' => 'nullable|numeric|min:0|max:100|decimal:0,2'],
        };
        $row->fill($r->validate($rules))->save();

        return redirect()->route('catalog', ['company' => $company, 'kind' => $kind])->with('success', 'Cadastro salvo.');
    }

    public function stock()
    {
        return view('stock', ['products' => Product::where('active', true)->orderBy('name')->get(), 'movements' => StockMovement::with('product')->latest()->paginate(25)]);
    }

    public function moveStock(Request $r, string $company)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'product_id' => 'required|exists:tenant.products,id', 'type' => 'required|in:in,out', 'quantity' => 'required|numeric|min:0.001|max:999999999|decimal:0,3', 'unit_cost' => 'required|numeric|min:0|max:999999999.9999|decimal:0,4', 'reason' => 'required|string|min:3|max:500']);
        app(Inventory::class)->move((int) $d['product_id'], $d['type'], (string) $d['quantity'], (string) $d['unit_cost'], $d['reason'], $r->attributes->get('currentUser')->id, $d['request_key']);

        return back()->with('success', 'Movimentação registrada.');
    }

    public function sales()
    {
        return view('sales.index', ['sales' => Sale::with('customer')->latest()->paginate(20)]);
    }

    public function saleForm()
    {
        return view('sales.form', ['customers' => Customer::orderBy('name')->get(), 'products' => Product::where('active', true)->orderBy('name')->get(), 'services' => Service::where('active', true)->orderBy('name')->get()]);
    }

    public function checkout(Request $r, string $company)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'customer_id' => 'nullable|exists:tenant.customers,id', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'paid' => 'required|boolean', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24', 'notes' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:50', 'items.*.kind' => 'required|in:product,service', 'items.*.id' => 'required|integer|min:1', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:999999999.99|decimal:0,2']);
        $d['installments'] = $r->integer('installments', 1);
        $sale = app(Checkout::class)->complete($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $sale->id])->with('success', 'Venda registrada e estoque atualizado.');
    }

    public function sale(Request $r, string $company, int $id)
    {
        return view('sales.show', ['sale' => Sale::with('items', 'customer')->findOrFail($id), 'received' => FinancialEntry::where('sale_id', $id)->sum('paid_amount')]);
    }

    public function cancel(Request $r, string $company, int $id)
    {
        $this->admin($r);
        app(Checkout::class)->cancel($id, $r->attributes->get('currentUser')->id, $r->boolean('refund_confirmed'));

        return back()->with('success', 'Venda cancelada, estoque estornado e histórico financeiro preservado.');
    }

    public function receive(Request $r, string $company, int $id)
    {
        $this->admin($r);
        DB::connection('tenant')->transaction(function () use ($r, $id) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            abort_unless($sale->status === 'completed', 422);
            foreach (FinancialEntry::where('sale_id', $id)->whereNull('cancelled_at')->orderBy('id')->get() as $entry) {
                $balance = bcsub($entry->amount, $entry->paid_amount, 2);
                if (bccomp($balance, '0', 2) > 0) {
                    app(FinancialService::class)->pay($entry->id, $balance, now()->toDateString(), $sale->payment_method, $r->attributes->get('currentUser')->id, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:full-receipt:'.$id.':'.$entry->id));
                }
            }
        }, 3);

        return back()->with('success', 'Saldo da venda recebido e baixas registradas.');
    }

    public function users(Request $r)
    {
        $this->admin($r);

        return view('users', ['users' => User::orderBy('name')->get()]);
    }

    public function saveUser(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['name' => 'required|string|max:180', 'email' => 'required|email|max:180|unique:tenant.users,email', 'password' => 'required|string|min:12|max:128|confirmed', 'role' => 'required|in:admin,operator']);
        unset($d['password_confirmation']);
        $d['must_change_password'] = true;
        User::create($d);

        return back()->with('success','Usuário criado.');
    }
}
