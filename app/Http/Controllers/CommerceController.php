<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Seller;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Checkout;
use App\Services\CommercialRules;
use App\Services\SellerFormContext;
use App\Services\FinancialService;
use App\Services\Inventory;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class CommerceController extends Controller
{
    private function admin(Request $r): void
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
    }

    public function dashboard(Request $request)
    {
        $sales = Sale::where('status', 'completed');
        $currentYear = (int) now()->year;
        $data = $request->validate(['year'=>'nullable|integer|min:2000|max:'.$currentYear]);
        $years = (clone $sales)->whereYear('created_at', '<=', $currentYear)
            ->selectRaw('YEAR(created_at) as sales_year')->distinct()->orderBy('sales_year')
            ->pluck('sales_year')->map(fn ($value) => (int) $value)->all();
        $pastYears = array_values(array_filter($years, fn ($year) => $year < $currentYear));
        $defaultYear = $pastYears === [] ? $currentYear : max($pastYears);
        $salesYear = (int) ($data['year'] ?? $defaultYear);
        $salesYears = array_values(array_unique(array_merge($years, [$currentYear, $salesYear])));
        sort($salesYears);
        $from = now()->setDate($salesYear, 1, 1)->startOfDay();
        $until = $from->copy()->endOfYear();
        $period = (clone $sales)->whereBetween('created_at', [$from, $until]);
        $monthly = (clone $period)->selectRaw('MONTH(created_at) as month, SUM(total) as total, COUNT(*) as sales_count')
            ->groupByRaw('MONTH(created_at)')->get()->keyBy('month');
        $labels = ['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];
        $salesTrend = [];
        for ($month = 1; $month <= 12; $month++) {
            $salesTrend[] = ['date'=>sprintf('%04d-%02d-01', $salesYear, $month), 'label'=>$labels[$month-1],
                'total'=>(string) ($monthly->get($month)?->total ?? '0.00'),
                'count'=>(int) ($monthly->get($month)?->sales_count ?? 0)];
        }
        return view('dashboard', [
            'salesYear'=>$salesYear,'salesYears'=>$salesYears,'salesTrend'=>$salesTrend,'salesPeriodTotal'=>(string)(clone $period)->sum('total'),'salesPeriodCount'=>(clone $period)->count(),
            'revenue' => (clone $sales)->sum('total'), 'cost' => (clone $sales)->sum('cost_total'),
            'receivable' => FinancialEntry::where('direction', 'in')->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount-paid_amount),0) as outstanding_total')->value('outstanding_total'),
            'stockValue' => Product::selectRaw('COALESCE(SUM(quantity * average_cost),0) as value')->value('value'),
            'lowStock' => Product::where('active', true)->where('type', 'product')->where('controls_stock', true)->whereColumn('quantity', '<=', 'minimum_stock')->orderBy('name')->limit(8)->get(),
            'recent' => Sale::with('customer')->latest()->limit(8)->get(),
        ]);
    }

    public function products(Request $r)
    {
        $r->validate(['type'=>'nullable|in:product,service']);
        return view('products.index', ['products' => Product::with('group','priceReview')->when($r->query('type'),fn($q)=>$q->where('type',$r->query('type')))->when($r->q, fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$r->q.'%')->orWhere('sku', 'like', '%'.$r->q.'%')->orWhere('barcode', 'like', '%'.$r->q.'%')))->orderBy('name')->paginate(20)->withQueryString()]);
    }

    public function productForm(Request $r, string $company, ?int $id = null)
    {
        $this->admin($r);

        return view('products.form', ['product' => $id ? Product::findOrFail($id) : new Product(['type'=>$r->query('type')==='service'?'service':'product','controls_stock'=>$r->query('type')!=='service','reference_cost'=>'0.0000','unit' => 'UN', 'sale_price' => 0, 'minimum_stock' => 0, 'active' => true]), 'groups' => ProductGroup::orderBy('name')->get()]);
    }

    public function saveProduct(Request $r, string $company, ?int $id = null)
    {
        $this->admin($r);
        $p = $id ? Product::findOrFail($id) : new Product;
        $d = $r->validate(['name' => 'required|string|max:180', 'sku' => ['required', 'string', 'max:80', Rule::unique('tenant.products', 'sku')->ignore($p->id)], 'barcode' => ['nullable', 'string', 'max:80', Rule::unique('tenant.products', 'barcode')->ignore($p->id)], 'discount_enabled'=>'nullable|boolean','max_discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','commission_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','type'=>'sometimes|required|in:product,service','controls_stock'=>'nullable|boolean','reference_cost'=>'nullable|numeric|min:0|max:999999999999.9999|decimal:0,4','municipal_service_code'=>'nullable|string|max:30','iss_rate'=>'nullable|numeric|min:0|max:100|decimal:0,2', 'product_group_id' => 'required|exists:tenant.product_groups,id', 'unit' => 'required|in:UN,KG,M,L,CX,PC,PAR', 'price_policy'=>'nullable|in:alert,automatic', 'margin' => 'nullable|numeric|min:0|max:99.99|decimal:0,2', 'sale_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'minimum_stock' => 'nullable|numeric|min:0|max:999999999|decimal:0,3', 'ncm' => 'nullable|digits:8', 'origin' => 'nullable|in:0,1,2,3,4,5,6,7,8', 'active' => 'nullable|boolean']);
        $d['active'] = $r->boolean('active');
        $d['type']=$d['type']??$p->type??'product';
        $d['controls_stock']=$d['type']==='product' ? ($r->has('controls_stock')?$r->boolean('controls_stock'):($p->exists?(bool)$p->controls_stock:true)) : false;
        if(!$d['controls_stock'])$d['minimum_stock']='0.000';
        $d['reference_cost']=$d['reference_cost']??$p->reference_cost??'0.0000';
        DB::connection('tenant')->transaction(function()use($r,$p,$d){
            if($p->exists){$locked=Product::lockForUpdate()->findOrFail($p->id);$previous=(string)$locked->sale_price;}else{$locked=$p;$previous=null;}
            if($locked->exists&&bccomp((string)$locked->quantity,'0',3)!==0&&(!$d['controls_stock']||$d['type']==='service'))throw ValidationException::withMessages(['controls_stock'=>'Zere o saldo com uma movimentação identificada antes de desativar o estoque ou trocar o tipo para serviço.']);
            $locked->fill($d)->save();
            app(PricingService::class)->syncProduct($locked->id,$r->attributes->get('currentUser')->id,$previous===null?'product:create':'product:update',$previous);
        },3);

        return redirect()->route('products', ['company' => $company])->with('success', 'Produto salvo.');
    }

    public function catalog(Request $r, string $company, string $kind)
    {
        if($kind==='services')return redirect()->route('products',['company'=>$company,'type'=>'service']);
        [$model,$title,$fields] = $this->catalogSpec($kind);

        return view('catalog', ['rows' => $model::orderBy('name')->paginate(20), 'title' => $title, 'fields' => $fields, 'kind' => $kind, 'editing' => $r->integer('edit') ? $model::findOrFail($r->integer('edit')) : null]);
    }

    private function catalogSpec(string $kind): array
    {
        return match ($kind) {
            'groups' => [ProductGroup::class, 'Grupos de produtos', ['name' => 'Nome', 'margin' => 'Margem padrão (%)','price_policy'=>'Atualização do preço de venda','discount_enabled'=>'Descontos permitidos','max_discount_percent'=>'Desconto máximo (%)','commission_percent'=>'Comissão (%)']],
            'suppliers' => [Supplier::class, 'Fornecedores', ['name' => 'Nome / razão social', 'document' => 'CPF / CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'address' => 'Endereço']],
            'customers' => [Customer::class, 'Clientes', ['name' => 'Nome / razão social', 'document' => 'CPF / CNPJ', 'email' => 'E-mail', 'phone' => 'Telefone', 'address' => 'Endereço']],
            'services' => [Service::class, 'Serviços', ['name' => 'Descrição do serviço', 'price' => 'Preço (R$)', 'cost' => 'Custo (R$)', 'municipal_service_code' => 'Código municipal (futuro fiscal)', 'iss_rate' => 'Alíquota ISS (%)']],
            default => abort(404),
        };
    }

    public function saveCatalog(Request $r, string $company, string $kind)
    {
        $this->admin($r);
        if($kind==='services')return $this->saveLegacyService($r,$company);
        [$model] = $this->catalogSpec($kind);
        $row = $r->integer('id') ? $model::findOrFail($r->integer('id')) : new $model;
        $rules = match ($kind) {
            'groups' => ['name' => ['required', 'string', 'max:180', Rule::unique('tenant.product_groups', 'name')->ignore($row->id)], 'margin' => 'required|numeric|min:0|max:99.99|decimal:0,2','price_policy'=>'sometimes|required|in:alert,automatic','discount_enabled'=>'sometimes|required|boolean','max_discount_percent'=>'sometimes|required|numeric|min:0|max:100|decimal:0,2','commission_percent'=>'sometimes|required|numeric|min:0|max:100|decimal:0,2'],
            'customers','suppliers' => ['name' => 'required|string|max:180', 'document' => 'nullable|string|max:20', 'email' => 'nullable|email|max:180', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000'],
            'services' => ['name' => 'required|string|max:180', 'price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'cost' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'municipal_service_code' => 'nullable|string|max:30', 'iss_rate' => 'nullable|numeric|min:0|max:100|decimal:0,2'],
        };
        $data=$r->validate($rules);
        DB::connection('tenant')->transaction(function()use($row,$data,$kind,$r){
            if($kind==='groups'&&$row->exists)$row=ProductGroup::lockForUpdate()->findOrFail($row->id);
            $row->fill($data)->save();
            if($kind==='groups')app(PricingService::class)->syncGroup($row->id,$r->attributes->get('currentUser')->id);
        },3);

        return redirect()->route('catalog', ['company' => $company, 'kind' => $kind])->with('success', 'Cadastro salvo.');
    }

    public function stock()
    {
        return view('stock', ['products' => Product::with('group')->where('active', true)->where('type','product')->where('controls_stock',true)->orderBy('name')->get(), 'movements' => StockMovement::with('product')->latest()->paginate(25)]);
    }

    public function moveStock(Request $r, string $company)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'product_id' => 'required|exists:tenant.products,id', 'type' => 'required|in:in,out', 'quantity' => 'required|numeric|min:0.001|max:999999999|decimal:0,3', 'unit_cost' => 'required|numeric|min:0|max:999999999.9999|decimal:0,4', 'reason' => 'required|string|min:3|max:500']);
        app(Inventory::class)->move((int) $d['product_id'], $d['type'], (string) $d['quantity'], (string) $d['unit_cost'], $d['reason'], $r->attributes->get('currentUser')->id, $d['request_key']);

        return back()->with('success', 'Movimentação registrada.');
    }

    public function sales(Request $request)
    {
        $data = $request->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id']);
        $sellerId = isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null;
        return view('sales.index', ['sales'=>Sale::with('customer')->when($sellerId,fn($q)=>$q->where('salesperson_id',$sellerId))->latest()->paginate(20)->withQueryString(),
            'sellers'=>Seller::orderBy('name')->get(),'sellerId'=>$sellerId]);
    }

    public function saleForm(Request $r)
    {
        $data=$r->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','customer_id'=>'nullable|integer|exists:tenant.customers,id']);
        $context=app(SellerFormContext::class)->forUser($r->attributes->get('currentUser'),isset($data['salesperson_id'])?(int)$data['salesperson_id']:null);
        return view('sales.form',array_merge($context,['customers'=>Customer::orderBy('name')->get(),
            'products'=>Product::with('group')->where('active',true)->orderBy('name')->get(),'services'=>collect(),
            'selectedCustomerId'=>$data['customer_id']??null]));
    }

    public function checkout(Request $r, string $company)
    {
        $d = $r->validate(['request_key' => 'required|uuid', 'customer_id' => 'nullable|exists:tenant.customers,id', 'payment_method' => 'required|in:cash,pix,credit,debit,transfer,invoice', 'paid' => 'required|boolean', 'due_date' => 'nullable|required_if:paid,0|date_format:Y-m-d', 'installments' => 'nullable|integer|min:1|max:24', 'notes' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:50', 'items.*.kind' => 'required|in:product,service', 'items.*.id' => 'required|integer|min:1', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'seller_id'=>'nullable|integer|exists:tenant.users,id','salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','items.*.discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','items.*.catalog_price_seen'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2']);
        $d['installments'] = $r->integer('installments', 1);
        $sale = app(Checkout::class)->complete($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $sale->id])->with('success', 'Venda registrada e estoque atualizado.');
    }

    public function sale(Request $r, string $company, int $id)
    {
        return view('sales.show', ['fiscalDocuments'=>\App\Models\FiscalDocument::where('sale_id',$id)->orderBy('id')->get(), 'sale' => Sale::with('items', 'customer','creator')->findOrFail($id), 'received' => FinancialEntry::where('sale_id', $id)->sum('paid_amount')]);
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

        return back()->with('success', 'Usuário criado.');
    }
    private function saveLegacyService(Request $r,string $company){
        $d=$r->validate(['name'=>'required|string|max:180','price'=>'required|numeric|min:0|max:999999999999.99|decimal:0,2','cost'=>'required|numeric|min:0|max:999999999999.9999|decimal:0,4','municipal_service_code'=>'nullable|string|max:30','iss_rate'=>'nullable|numeric|min:0|max:100|decimal:0,2']);
        DB::connection('tenant')->transaction(function()use($r,$d){
            $group=ProductGroup::firstOrCreate(['name'=>'Serviços'],['margin'=>'30.00','price_policy'=>'alert']);
            if($r->integer('id')){$legacy=Service::findOrFail($r->integer('id'));$p=Product::where('legacy_service_id',$legacy->id)->lockForUpdate()->firstOrFail();}
            else{$p=new Product(['sku'=>'SERV-'.strtoupper(bin2hex(random_bytes(5))),'product_group_id'=>$group->id,'unit'=>'UN','active'=>true,'price_policy'=>'alert']);}
            $before=$p->exists?(string)$p->sale_price:null;
            if($p->exists&&bccomp((string)$p->quantity,'0',3)!==0)throw ValidationException::withMessages(['type'=>'Este cadastro possui saldo. Ajuste o estoque antes de trocar o tipo.']);
            $p->fill(['name'=>$d['name'],'type'=>'service','controls_stock'=>false,'reference_cost'=>$d['cost'],'sale_price'=>$d['price'],'minimum_stock'=>0,'municipal_service_code'=>$d['municipal_service_code']??null,'iss_rate'=>$d['iss_rate']??null])->save();
            app(PricingService::class)->syncProduct($p->id,$r->attributes->get('currentUser')->id,'service:update',$before);
        },3);
        return redirect()->route('products',['company'=>$company,'type'=>'service'])->with('success','Serviço salvo no cadastro unificado.');
    }
}
