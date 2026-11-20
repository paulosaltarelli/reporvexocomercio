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
        app(\App\Access\AccessProfiles::class)->authorize($r);
    }

    public function dashboard(Request $request)
    {
        if ($request->attributes->get('currentUser')->role==='admin' && app(\App\Services\CompanyOnboarding::class)->required($request->attributes->get('tenant'))) return redirect()->route('setup',['company'=>$request->route('company')]);
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
            'salesBreakdown'=>$request->attributes->get('currentUser')->allows('dashboard.view')?app(\App\Services\SalesBreakdown::class)->year($salesYear):null,
            'salesYear'=>$salesYear,'salesYears'=>$salesYears,'salesTrend'=>$salesTrend,'salesPeriodTotal'=>(string)(clone $period)->sum('total'),'salesPeriodCount'=>(clone $period)->count(),
            'revenue' => (clone $sales)->sum('total'), 'cost' => (clone $sales)->sum('cost_total'),
            'receivable' => FinancialEntry::where('direction', 'in')->whereNull('cancelled_at')->selectRaw('COALESCE(SUM(amount-paid_amount),0) as outstanding_total')->value('outstanding_total'),
            'stockValue' => app(Inventory::class)->totalValue(),
            'lowStock' => Product::where('active', true)->where('type', 'product')->where('controls_stock', true)->whereColumn('quantity', '<=', 'minimum_stock')->orderBy('name')->limit(8)->get(),
            'recent' => Sale::with('customer')->latest()->limit(8)->get(),
        ]);
    }

    public function products(Request $r)
    {
        $data = $r->validate(['type' => 'nullable|in:product,service', 'q' => 'nullable|string|max:100']);
        $search = trim($data['q'] ?? '');
        $typeFilter = $data['type'] ?? null;
        $products = Product::with('group', 'priceReview')
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->when($search !== '', function ($q) use ($search) {
                $needle = '%'.addcslashes($search, '\\%_').'%';
                $q->where(fn ($query) => $query->where('name', 'like', $needle)->orWhere('sku', 'like', $needle)->orWhere('barcode', 'like', $needle));
            })->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
        return view('products.index', compact('products', 'search', 'typeFilter'));
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
        $d = $r->validate(['name' => 'required|string|max:180', 'sku' => ['required', 'string', 'max:80', Rule::unique('tenant.products', 'sku')->ignore($p->id)], 'barcode' => ['nullable', 'string', 'max:80', Rule::unique('tenant.products', 'barcode')->ignore($p->id)], 'discount_enabled'=>'nullable|boolean','max_discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','commission_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','type'=>'sometimes|required|in:product,service','controls_stock'=>'nullable|boolean','reference_cost'=>'nullable|numeric|min:0|max:999999999999.9999|decimal:0,4','municipal_service_code'=>'nullable|string|max:30','iss_rate'=>'nullable|numeric|min:0|max:100|decimal:0,2', 'product_group_id' => 'required|exists:tenant.product_groups,id', 'unit' => ['required',Rule::in(array_keys(\App\Services\MeasureUnits::catalog(app(\App\Services\CompanyOperations::class)->current())))], 'price_policy'=>'nullable|in:alert,automatic', 'margin' => 'nullable|numeric|min:0|max:99.99|decimal:0,2', 'sale_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'minimum_stock' => 'nullable|numeric|min:0|max:999999999|decimal:0,3', 'ncm' => config('fiscal.product_invoices_enabled')?'nullable|digits:8':'exclude', 'origin' => config('fiscal.product_invoices_enabled')?'nullable|in:0,1,2,3,4,5,6,7,8':'exclude', 'active' => 'nullable|boolean']);
        $d['active'] = $r->boolean('active');
        $d['type']=$d['type']??$p->type??'product';
        $d['controls_stock']=$d['type']==='product' ? ($r->has('controls_stock')?$r->boolean('controls_stock'):($p->exists?(bool)$p->controls_stock:true)) : false;
        if(!$d['controls_stock'])$d['minimum_stock']='0.000';
        $d['reference_cost']=$d['reference_cost']??$p->reference_cost??'0.0000';
        DB::connection('tenant')->transaction(function()use($r,$p,$d){
            $configuration=app(\App\Services\CompanyOperations::class)->lock();
            if($d['active']) app(\App\Services\CompanyOperations::class)->assertItem($d['type'],$configuration);
            if(!$configuration['inventory'] && $d['controls_stock']) throw ValidationException::withMessages(['controls_stock'=>'Desmarque o controle de estoque ou habilite a gestão de estoque da empresa.']);
            if($p->exists){$locked=Product::lockForUpdate()->findOrFail($p->id);$previous=(string)$locked->sale_price;}else{$locked=$p;$previous=null;}
            if($locked->exists&&bccomp((string)$locked->quantity,'0',3)!==0&&(!$d['controls_stock']||$d['type']==='service'))throw ValidationException::withMessages(['controls_stock'=>'Zere o saldo com uma movimentação identificada antes de desativar o estoque ou trocar o tipo para serviço.']);
            if ($locked->exists && $d['unit'] !== $locked->unit && (
                !empty($configuration['unit_links'][$locked->id]['units'])
                || bccomp((string)$locked->quantity,'0',3) !== 0
                || \App\Models\StockMovement::where('product_id',$locked->id)->exists()
                || \App\Models\SaleItem::where('product_id',$locked->id)->exists()
                || \App\Models\PurchaseItem::where('product_id',$locked->id)->exists()
                || \App\Models\Document::whereJsonContains('items', ['kind'=>'product','id'=>$locked->id])->exists()
            )) throw ValidationException::withMessages(['unit'=>'A unidade de estoque já está em uso. Cadastre uma equivalência para comprar ou vender em outra unidade.']);
            $locked->fill($d)->save();
            app(PricingService::class)->syncProduct($locked->id,$r->attributes->get('currentUser')->id,$previous===null?'product:create':'product:update',$previous);
        },3);

        return redirect()->route('products', ['company' => $company])->with('success', 'Produto salvo.');
    }

    public function catalog(Request $r, string $company, string $kind)
    {
        if($kind==='services')return redirect()->route('products',['company'=>$company,'type'=>'service']);
        [$model,$title,$fields] = $this->catalogSpec($kind);

        $filters = $this->catalogFilters($r, $kind);
        $search = $filters['q'] ?? '';
        $query = $model::query();
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
                $query->whereRaw("name LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
                $document = \App\Fiscal\TaxpayerDocument::normalize($search);
                if (preg_match('/^[A-Z0-9]+$/D', $document)) {
                    $query->orWhereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(document, '.', ''), '/', ''), '-', ''), ' ', '')) LIKE ?", ['%'.$document.'%']);
                }
            });
        }
        $rows = $query->orderBy('name')->orderBy('id')->paginate(20)->appends(['q' => $filters['q'] ?? null]);

        return view('catalog', ['rows' => $rows, 'search' => $search, 'filters' => $filters, 'title' => $title, 'fields' => $fields, 'kind' => $kind, 'editing' => $r->integer('edit') ? $model::findOrFail($r->integer('edit')) : null]);
    }

    private function catalogFilters(Request $request, string $kind): array
    {
        if (!in_array($kind, ['customers', 'suppliers'], true)) return [];
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        return array_filter($filters, fn ($value) => $value !== null && $value !== '');
    }

    public function lookupCustomerRegistry(Request $request, \App\Services\CustomerRegistryLookup $registry)
    {
        $this->admin($request);
        $data = $request->validate(['document' => 'required|string|max:24']);

        return response()->json(['fields' => $registry->find($data['document'])])
            ->header('Cache-Control', 'no-store');
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
        $filters = $this->catalogFilters($r, $kind);
        $row = $r->integer('id') ? $model::findOrFail($r->integer('id')) : new $model;
        $rules = match ($kind) {
            'groups' => ['name' => ['required', 'string', 'max:180', Rule::unique('tenant.product_groups', 'name')->ignore($row->id)], 'margin' => 'required|numeric|min:0|max:99.99|decimal:0,2','price_policy'=>'sometimes|required|in:alert,automatic','discount_enabled'=>'sometimes|required|boolean','max_discount_percent'=>'sometimes|required|numeric|min:0|max:100|decimal:0,2','commission_percent'=>'sometimes|required|numeric|min:0|max:100|decimal:0,2'],
            'customers' => \App\Services\CustomerFields::RULES,
            'suppliers' => ['name' => 'required|string|max:180', 'document' => 'nullable|string|max:20', 'email' => 'nullable|email|max:180', 'phone' => 'nullable|string|max:30', 'address' => 'nullable|string|max:1000'],
            'services' => ['name' => 'required|string|max:180', 'price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'cost' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'municipal_service_code' => 'nullable|string|max:30', 'iss_rate' => 'nullable|numeric|min:0|max:100|decimal:0,2'],
        };
        if ($kind === 'customers') {
            $r->merge(\App\Services\CustomerFields::normalize($r->only(array_keys($rules))));
        }
        $data=$r->validate($rules);
        DB::connection('tenant')->transaction(function()use($row,$data,$kind,$r){
            app(\App\Services\CompanyOperations::class)->lock();
            if($kind==='groups'&&$row->exists)$row=ProductGroup::lockForUpdate()->findOrFail($row->id);
            $row->fill($data)->save();
            if($kind==='groups')app(PricingService::class)->syncGroup($row->id,$r->attributes->get('currentUser')->id);
        },3);

        return redirect()->route('catalog', ['company' => $company, 'kind' => $kind] + $filters)->with('success', 'Cadastro salvo.');
    }

    public function stock()
    {
        return view('stock', ['products' => Product::with('group')->where('active', true)->where('type','product')->where('controls_stock',true)->orderBy('name')->get(), 'movements' => StockMovement::with('product')->latest()->paginate(25)]);
    }

    public function moveStock(Request $r, string $company)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'product_id' => 'required|exists:tenant.products,id', 'type' => 'required|in:in,out', 'quantity' => 'required|numeric|min:0.001|max:999999999|decimal:0,3', 'unit_cost' => 'required|numeric|min:0|max:999999999.9999|decimal:0,4', 'reason' => 'required|string|min:3|max:500']);
        $product = app(Inventory::class)->move((int) $d['product_id'], $d['type'], (string) $d['quantity'], (string) $d['unit_cost'], $d['reason'], $r->attributes->get('currentUser')->id, $d['request_key']);

        $response = back()->with('success', 'Movimentação registrada.');
        if ($d['type'] === 'out' && bccomp($product->quantity, '0', 3) < 0
            && app(\App\Services\CompanyOperations::class)->current()['negative_stock'] === 'warn') {
            $response->with('stock_warning', 'Saída registrada com estoque negativo: '.$product->name.'.');
        }

        return $response;
    }

    public function sales(Request $request)
    {
        $data = $request->validate([
            'salesperson_id' => 'nullable|integer|exists:tenant.sellers,id', 'q' => 'nullable|string|max:100',
            'status' => 'nullable|in:completed,cancelled', 'payment_status' => 'nullable|in:paid,pending',
            'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : ''),
        ]);
        $sellerId = isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null;
        $search = trim($data['q'] ?? '');
        $statusFilter = $data['status'] ?? null;
        $paymentStatus = $data['payment_status'] ?? null;
        $from = $data['from'] ?? null;
        $to = $data['to'] ?? null;
        $sales = Sale::with('customer')
            ->when($sellerId, fn ($q) => $q->where('salesperson_id', $sellerId))
            ->when($statusFilter, fn ($q) => $q->where('status', $statusFilter))
            ->when($paymentStatus, fn ($q) => $q->where('paid', $paymentStatus === 'paid'))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to.' 23:59:59.999999'))
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $needle = '%'.addcslashes($search, '\\%_').'%';
                    $q->where('notes', 'like', $needle)->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $needle)->orWhere('document', 'like', $needle)->orWhere('phone', 'like', $needle));
                    if (ctype_digit($search) && strlen($search) <= 18) { $q->orWhere('id', (int) $search); }
                });
            })->latest('created_at')->latest('id')->paginate(20)->withQueryString();
        return view('sales.index', compact('sales', 'sellerId', 'search', 'statusFilter', 'paymentStatus', 'from', 'to') + ['sellers' => Seller::orderBy('name')->get()]);
    }

    public function saleForm(Request $r)
    {
        $data=$r->validate(['salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','customer_id'=>'nullable|integer|exists:tenant.customers,id']);
        $context=app(SellerFormContext::class)->forUser($r->attributes->get('currentUser'),isset($data['salesperson_id'])?(int)$data['salesperson_id']:null);
        return view('sales.form',array_merge($context,['customers'=>Customer::orderBy('name')->get(),
            'products'=>Product::with('group')->where('active',true)->when(app(\App\Services\CompanyOperations::class)->current()['catalog_mode']!=='mixed',fn($q)=>$q->where('type',app(\App\Services\CompanyOperations::class)->current()['catalog_mode']==='services'?'service':'product'))->orderBy('name')->get(),'services'=>collect(),
            'selectedCustomerId'=>$data['customer_id']??null]));
    }

    public function checkout(Request $r, string $company)
    {
        $d = $r->validate(array_merge(\App\Services\SalePayments::rules(), ['request_key' => 'required|uuid', 'customer_id' => 'nullable|exists:tenant.customers,id', 'payment_method' => 'required_without:payments|in:cash,pix,credit,debit,transfer,invoice','payment_option'=>'nullable|string|max:60', 'paid' => 'required_without:payments|boolean', 'due_date' => ($r->has('payments')?'nullable|date_format:Y-m-d':'nullable|required_if:paid,0|date_format:Y-m-d'), 'installments' => 'nullable|integer|min:1|max:24', 'notes' => 'nullable|string|max:1000', 'items' => 'required|array|min:1|max:50', 'items.*.kind' => 'required|in:product,service', 'items.*.id' => 'required|integer|min:1', 'items.*.measure_unit'=>'nullable|string|max:10','items.*.factor_seen'=>'nullable|string|max:20','items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3', 'items.*.unit_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'seller_id'=>'nullable|integer|exists:tenant.users,id','salesperson_id'=>'nullable|integer|exists:tenant.sellers,id','items.*.discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2','items.*.catalog_price_seen'=>'nullable|numeric|min:0|max:999999999999.99|decimal:0,2']));
        $d['installments'] = $r->integer('installments', 1);
        $sale = app(Checkout::class)->complete($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('sale.show', ['company' => $company, 'id' => $sale->id])->with('success', 'Venda registrada.')
            ->with('stock_warning', app(Inventory::class)->saleWarning($sale->id));
    }

    public function sale(Request $r, string $company, int $id)
    {
        return view('sales.show', ['fiscalDocuments'=>config('fiscal.product_invoices_enabled')?\App\Models\FiscalDocument::where('sale_id',$id)->orderBy('id')->get():collect(), 'sale' => Sale::with('items', 'customer','creator')->findOrFail($id), 'received' => FinancialEntry::where('sale_id', $id)->sum('paid_amount')]);
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
        $r->validate(['payment_option'=>'nullable|string|max:60']);
        DB::connection('tenant')->transaction(function () use ($r, $id) {
            $sale = Sale::lockForUpdate()->findOrFail($id);
            abort_unless($sale->status === 'completed', 422);
            if(app(\App\Services\SalePayments::class)->rows($id) && !$r->filled('payment_option'))throw \Illuminate\Validation\ValidationException::withMessages(['payment_option'=>'Escolha o meio efetivamente recebido para quitar o saldo.']);
            $choice=app(\App\Services\PaymentMethods::class)->resolve($r->input('payment_option'),$sale->payment_method);
            foreach (FinancialEntry::where('sale_id', $id)->whereNull('cancelled_at')->orderBy('id')->get() as $entry) {
                $balance = bcsub($entry->amount, $entry->paid_amount, 2);
                if (bccomp($balance, '0', 2) > 0) {
                    app(FinancialService::class)->pay($entry->id, $balance, now()->toDateString(), $choice['type'], $r->attributes->get('currentUser')->id, (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:full-receipt:'.$id.':'.$entry->id),$choice['id']);
                }
            }
        }, 3);

        return back()->with('success', 'Saldo da venda recebido e baixas registradas.');
    }

    public function users(Request $r)
    {
        $this->admin($r);

        return view('users', ['users' => User::where('role','<>', User::ROLE_INSTALLER)->orderBy('name')->get()->reject(fn($u)=>\App\Platform\SupportAccess::isManaged($u)), 'accessState'=>app(\App\Access\AccessProfiles::class)->state(), 'profileService'=>app(\App\Access\AccessProfiles::class)]);
    }

    public function saveUser(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['name' => 'required|string|max:180', 'email' => 'required|email|max:180|not_regex:/@support\.vexo\.invalid$/i|unique:tenant.users,email', 'password' => 'required|string|min:12|max:128|confirmed', 'role' => 'required|in:admin,operator','access_profile'=>'nullable|string|max:60']);
        unset($d['password_confirmation']);
        $d['must_change_password'] = true;
        $profile=$d['access_profile']??'operator';unset($d['access_profile']);
        DB::connection('tenant')->transaction(function()use($r,$d,$profile){
            $user=User::create($d);
            app(\App\Access\AccessProfiles::class)->assign($user->id,$user->role,$profile,(int)$r->attributes->get('currentUser')->id);
        },3);

        return back()->with('success', 'Usuário criado.');
    }
    private function saveLegacyService(Request $r,string $company){
        $d=$r->validate(['name'=>'required|string|max:180','price'=>'required|numeric|min:0|max:999999999999.99|decimal:0,2','cost'=>'required|numeric|min:0|max:999999999999.9999|decimal:0,4','municipal_service_code'=>'nullable|string|max:30','iss_rate'=>'nullable|numeric|min:0|max:100|decimal:0,2']);
        DB::connection('tenant')->transaction(function()use($r,$d){
            app(\App\Services\CompanyOperations::class)->lock();
            app(\App\Services\CompanyOperations::class)->assertItem('service');
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
