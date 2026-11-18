<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Service;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checkout;
use App\Services\Documents;
use App\Services\Inventory;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommerceTest extends TestCase
{
    private static bool $schemasReady = false;

    private Tenant $a;

    private Tenant $b;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure bancos MySQL dedicados conforme docs/TESTES.md.');
        }
        config(['database.default' => 'central', 'database.connections.central' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'vendra_test_central', 'username' => 'vendra_test', 'password' => env('VENDRA_TEST_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
        DB::purge('central');
        if (! self::$schemasReady) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            foreach (['a', 'b'] as $slug) {
                $tenant = new Tenant($this->tenantAttributes($slug));
                app(TenantConnection::class)->connect($tenant);
                Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            }
            self::$schemasReady = true;
        }
        $this->clearTestDatabase('central', 'vendra_test_central');
        foreach (['a', 'b'] as $slug) {
            $tenant = Tenant::create($this->tenantAttributes($slug));
            app(TenantConnection::class)->connect($tenant);
            $this->clearTestDatabase('tenant', 'vendra_t_test_'.$slug);
            $this->$slug = $tenant;
        }
        app(TenantConnection::class)->connect($this->a);
        $this->user = User::create(['name' => 'Administrador', 'email' => 'admin@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $g = ProductGroup::create(['name' => 'Geral', 'margin' => '40']);
        $this->product = Product::create(['name' => 'Produto teste', 'sku' => 'SKU-1', 'product_group_id' => $g->id, 'sale_price' => '100', 'unit' => 'UN']);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            app(TenantConnection::class)->disconnect();
            DB::purge('central');
        }
        parent::tearDown();
    }

    private function tenantAttributes(string $slug): array
    {
        return ['slug' => $slug, 'name' => 'Empresa '.$slug, 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_'.$slug, 'db_username' => 'vendra_test', 'db_password' => env('VENDRA_TEST_PASSWORD')];
    }

    /** Clear committed fixtures only in the three explicitly named test databases. */
    private function clearTestDatabase(string $connectionName, string $expectedDatabase): void
    {
        $allowed = ['vendra_test_central', 'vendra_t_test_a', 'vendra_t_test_b'];
        $connection = DB::connection($connectionName);
        if (! in_array($expectedDatabase, $allowed, true) || $connection->getDatabaseName() !== $expectedDatabase || $connection->getConfig('username') !== 'vendra_test') {
            throw new \RuntimeException('Limpeza recusada: conexão não é um banco Vendra de teste.');
        }
        $actual = (array) $connection->selectOne('SELECT DATABASE() AS current_database');
        if ($actual['current_database'] !== $expectedDatabase) {
            throw new \RuntimeException('Limpeza recusada: banco ativo não corresponde ao banco de teste.');
        }
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($connection->select('SHOW TABLES') as $tableRow) {
                $table = array_values((array) $tableRow)[0];
                if (in_array($table, ['migrations', 'cash_closure_locks'], true)) {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                    throw new \RuntimeException('Nome inesperado de tabela de teste.');
                }
                $connection->delete('DELETE FROM `'.$table.'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function move(string $type, string $qty, string $cost = '0', ?string $key = null): Product
    {
        return app(Inventory::class)->move($this->product->id, $type, $qty, $cost, 'Teste de estoque', $this->user->id, $key ?? Str::uuid());
    }

    private function saleData(string $qty = '1'): array
    {
        return ['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true, 'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => $qty, 'unit_price' => '100']]];
    }

    public function test_weighted_average_and_outgoing_cost(): void
    {
        $this->move('in', '10', '10');
        $p = $this->move('in', '20', '25');
        $this->assertSame('20.0000', $p->average_cost);
        $this->assertSame('30.000', $p->quantity);
        $p = $this->move('out', '5');
        $this->assertSame('20.0000', $p->average_cost);
        $this->assertSame('25.000', $p->quantity);
    }

    public function test_fractional_quantities_and_rounding(): void
    {
        $this->move('in', '0.500', '10');
        $p = $this->move('in', '1.000', '20');
        $this->assertSame('16.6667', $p->average_cost);
        $this->assertSame('1.500', $p->quantity);
        $sale = app(Checkout::class)->complete($this->saleData('0.001'), $this->user->id);
        $this->assertSame('0.0167', $sale->fresh()->cost_total);
    }

    public function test_insufficient_stock_rolls_back_sale_and_movements(): void
    {
        $this->move('in', '1', '10');
        try {
            app(Checkout::class)->complete($this->saleData('2'), $this->user->id);
            $this->fail('Should reject insufficient stock');
        } catch (ValidationException) {
            $this->assertSame(0, Sale::count());
            $this->assertSame(1, StockMovement::count());
            $this->assertSame('1.000', $this->product->fresh()->quantity);
        }
    }

    public function test_sale_cancel_and_repeat_are_idempotent(): void
    {
        $this->move('in', '10', '60');
        $d = $this->saleData('2');
        $s = app(Checkout::class)->complete($d, $this->user->id);
        $this->assertSame('200.00', $s->fresh()->total);
        $this->assertSame('120.0000', $s->fresh()->cost_total);
        $this->assertSame($s->id, app(Checkout::class)->complete($d, $this->user->id)->id);
        $this->assertSame('8.000', $this->product->fresh()->quantity);
        app(Checkout::class)->cancel($s->id, $this->user->id, true);
        app(Checkout::class)->cancel($s->id, $this->user->id, true);
        $this->assertSame('10.000', $this->product->fresh()->quantity);
        $this->assertSame(3, StockMovement::count());
    }

    public function test_mixed_sale_includes_service_cost_without_stock_movement(): void
    {
        $this->move('in', '2', '60');
        $service = Service::create(['name' => 'Instalação', 'price' => '50', 'cost' => '15']);
        $d = $this->saleData();
        $d['items'][] = ['kind' => 'service', 'id' => $service->id, 'quantity' => '1', 'unit_price' => '50'];
        $s = app(Checkout::class)->complete($d, $this->user->id);
        $this->assertSame('150.00', $s->fresh()->total);
        $this->assertSame('75.0000', $s->fresh()->cost_total);
        $this->assertSame(2, StockMovement::count());
    }

    public function test_stock_request_is_idempotent(): void
    {
        $key = (string) Str::uuid();
        $this->move('in', '10', '10', $key);
        $p = $this->move('in', '10', '10', $key);
        $this->assertSame('10.000', $p->quantity);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_databases_and_user_context_are_isolated(): void
    {
        $this->move('in', '10', '10');
        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, User::count());
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->get('/app/b/')->assertRedirect('/app/b/login');
    }

    public function test_login_dashboard_and_all_views_render(): void
    {
        $this->get('/app/a/login')->assertOk()->assertSee('Empresa a');
        $this->post('/app/a/login', ['email' => 'admin@example.test', 'password' => 'test-password-123'])->assertRedirect('/app/a');
        foreach (['/app/a', '/app/a/desk', '/app/a/products', '/app/a/products/new', '/app/a/catalog/groups', '/app/a/catalog/customers', '/app/a/products?type=service', '/app/a/stock', '/app/a/sales', '/app/a/sales/new', '/app/a/users', '/app/a/catalog/suppliers', '/app/a/documents', '/app/a/documents/new?type=quote', '/app/a/documents/new?type=work_order', '/app/a/finance', '/app/a/purchases', '/app/a/purchases/new', '/app/a/reports', '/app/a/account/password', '/app/a/business', '/app/a/cash', '/app/a/cashflow', '/app/a/prices'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/app/a/catalog/services')->assertRedirect('/app/a/products?type=service');
    }

    public function test_experience_navigation_marks_current_fiscal_section_and_hides_admin_links_from_operator(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Cliente navegação fiscal']);
        $this->product->update(['controls_stock' => false]);
        $sale = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        foreach ([
            '/app/a/fiscal' => '/app/a/fiscal',
            '/app/a/fiscal/company' => '/app/a/fiscal',
            '/app/a/nfse' => '/app/a/nfse',
            '/app/a/fiscal/profiles' => '/app/a/fiscal/profiles',
            '/app/a/fiscal/simulations' => '/app/a/fiscal/simulations',
            '/app/a/business' => '/app/a/business',
            '/app/a/sales/'.$sale->id.'/fiscal' => '/app/a/fiscal',
            '/app/a/fiscal/nfe/customers/'.$customer->id => '/app/a/fiscal',
        ] as $path => $activePath) {
            $response = $this->get($path)->assertOk();
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $active = $xpath->query('//nav[@aria-label="Menu da empresa"]//a[@aria-current="page"]');
            $this->assertCount(1, $active, $path);
            $this->assertSame($activePath, parse_url($active->item(0)->getAttribute('href'), PHP_URL_PATH));
            $this->assertCount(1, $xpath->query('//main[@id="main-content"]'));
            $this->assertCount(1, $xpath->query('//a[@href="#main-content"]'));
        }
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['role' => 'operator']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $response = $this->get('/app/a/desk')->assertOk();
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $links = [];
        foreach ($xpath->query('//nav[@aria-label="Menu da empresa"]//a') as $link) {
            $links[] = parse_url($link->getAttribute('href'), PHP_URL_PATH);
        }
        $this->assertContains('/app/a/sales', $links);
        $this->assertContains('/app/a/documents', $links);
        foreach (['fiscal', 'nfse', 'finance', 'cash', 'cashflow', 'reports', 'users', 'business', 'commissions'] as $section) {
            $this->assertNotContains('/app/a/'.$section, $links);
            $this->get('/app/a/'.$section)->assertForbidden();
        }
    }

    public function test_navigation_omits_empty_stock_group_and_keeps_account_and_history_accessible(): void
    {
        app(\App\Services\CompanyOperations::class)->save(
            $this->operationPayload(['inventory' => false, 'commissions' => false, 'quotes' => false, 'work_orders' => false]),
            $this->user->id
        );
        $this->user->update(['role' => 'operator']);
        $response = $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])
            ->get('/app/a/desk')->assertOk();
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertCount(0, $xpath->query('//nav//details[not(.//a)]'));
        $this->assertCount(0, $xpath->query('//nav//a[contains(@href,"/stock")]'));
        $this->assertCount(1, $xpath->query('//aside//a[contains(@href,"/account/password")]'));
        $this->assertCount(1, $xpath->query('//nav//a[contains(@href,"/documents")]'));
        $this->assertCount(0, $xpath->query('//nav//a[contains(@href,"/documents/new")]'));
        $this->assertCount(1, $xpath->query('//button[@class="menu-toggle"][@aria-controls="company-navigation"]'));
        $this->get('/app/a/account/password')->assertOk();
    }

    public function test_dashboard_and_desk_hide_disabled_operation_shortcuts_without_removing_history(): void
    {
        $this->product->update(['active' => false]);
        app(\App\Services\CompanyOperations::class)->save($this->operationPayload([
            'catalog_mode' => 'services', 'inventory' => false, 'nfce' => false, 'nfe' => false,
            'quotes' => false, 'work_orders' => false,
        ]), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a')->assertOk()
            ->assertDontSee('Registrar compra')->assertDontSee('Acompanhar OS')
            ->assertDontSee('Repor estoque')->assertDontSee('Estoque a custo médio')
            ->assertSee('Conferir financeiro')->assertSee('Últimas vendas');
        $this->get('/app/a/desk')->assertOk()
            ->assertDontSee('Acompanhar ordens de serviço')->assertDontSee('Orçamentos para continuar')
            ->assertDontSee('Orçamentos em andamento')->assertDontSee('Criar orçamento')
            ->assertSee('Registrar venda')->assertSee('Alterar minha senha');
        $this->get('/app/a/documents')->assertOk();
        $this->get('/app/a/purchases')->assertOk();
    }

    public function test_navigation_marks_nested_catalog_documents_and_settings_destinations(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        foreach ([
            '/app/a/products/'.$this->product->id.'/edit' => '/app/a/products',
            '/app/a/catalog/customers' => '/app/a/catalog/customers',
            '/app/a/documents/new?type=work_order' => '/app/a/documents',
            '/app/a/setup?step=2' => '/app/a/setup',
            '/app/a/account/password' => '/app/a/account/password',
        ] as $path => $destination) {
            $response = $this->get($path)->assertOk();
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $active = $xpath->query('//aside//a[@aria-current="page"]');
            $this->assertCount(1, $active, $path);
            $this->assertSame($destination, parse_url($active->item(0)->getAttribute('href'), PHP_URL_PATH));
            if (!str_contains($path, '/account/')) {
                $this->assertCount(1, $xpath->query('//details[@open]//a[@aria-current="page"]'), $path);
            }
        }
    }

    public function test_experience_document_filters_and_print_branding_preserve_company_context(): void
    {
        \App\Models\BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Empresa de teste & Filhos', 'document' => '11222333000181']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $response = $this->get('/app/a/documents?type=work_order&status=draft&q=Notebook%20%26%20Tela')->assertOk();
        $dom = new \DOMDocument();
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('Notebook & Tela', $xpath->query('//input[@name="q"]')->item(0)->getAttribute('value'));
        foreach (['type' => 'work_order', 'status' => 'draft'] as $field => $value) {
            $this->assertSame($value, $xpath->query('//select[@name="'.$field.'"]/option[@selected]')->item(0)->getAttribute('value'));
        }
        foreach (['reports', 'finance', 'cash', 'cashflow'] as $section) {
            $response = $this->get('/app/a/'.$section)->assertOk()->assertSee('Empresa de teste &amp; Filhos', false);
            $dom = new \DOMDocument();
            @$dom->loadHTML($response->getContent());
            $xpath = new \DOMXPath($dom);
            $logos = $xpath->query('//div[contains(concat(" ",normalize-space(@class)," ")," company-report-header ")]//img');
            $this->assertCount(1, $logos);
            $this->assertSame('/app/a/business/logo', parse_url($logos->item(0)->getAttribute('src'), PHP_URL_PATH));
        }
    }

    public function test_experience_sales_filters_preserve_pagination_and_products_search_zero_rejects_arrays(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Cliente Alfa']);
        $seller = \App\Models\Seller::create(['name' => 'Vendedor filtro', 'active' => true]);
        $other = \App\Models\Seller::create(['name' => 'Outro vendedor', 'active' => true]);
        $make = fn (array $overrides = []) => Sale::create(array_merge(['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'salesperson_id' => $seller->id, 'user_id' => $this->user->id, 'payment_method' => 'cash', 'paid' => false, 'total' => '100', 'cost_total' => '20', 'notes' => 'Pedido 0% literal', 'created_at' => '2026-11-10 23:59:59'], $overrides));
        $ids = [];
        for ($i=0; $i<22; $i++) { $ids[] = $make()->id; }
        foreach ([['created_at'=>'2026-11-09 23:59:59'], ['created_at'=>'2026-11-11 00:00:00'], ['status'=>'cancelled'], ['paid'=>true], ['salesperson_id'=>$other->id], ['notes'=>'Pedido 099 literal']] as $override) { $make($override); }
        $this->product->update(['name'=>'Zero 0']);
        Product::create(['name'=>'Sem algarismo','sku'=>'LETTERS','product_group_id'=>$this->product->product_group_id,'sale_price'=>'10']);
        $this->withSession(['tenant_id'=>$this->a->id, 'user_id'=>$this->user->id]);
        $query = ['salesperson_id'=>$seller->id, 'q'=>'0%', 'status'=>'completed', 'payment_status'=>'pending', 'from'=>'2026-11-10', 'to'=>'2026-11-10'];
        $response = $this->get('/app/a/sales?'.http_build_query($query))->assertOk();
        $page = $response->viewData('sales');
        $this->assertSame(22, $page->total());
        $expected = array_reverse($ids);
        $this->assertSame(array_slice($expected,0,20), $page->pluck('id')->all());
        parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $nextQuery);
        foreach ($query as $field=>$value) { $this->assertSame((string)$value, (string)$nextQuery[$field]); }
        $this->get('/app/a/sales?'.http_build_query($query+['page'=>2]))->assertOk()->assertViewHas('sales',fn($rows)=>$rows->pluck('id')->all()===array_slice($expected,20));
        $this->get('/app/a/products?q=0')->assertOk()->assertViewHas('products',fn($rows)=>$rows->pluck('id')->all()===[$this->product->id]);
        foreach (['products','sales','finance'] as $path) {
            $this->getJson('/app/a/'.$path.'?q[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('q');
        }
        $this->from('/app/a/products')->get('/app/a/products?q[]=invalid')->assertRedirect('/app/a/products')->assertSessionHasErrors('q');
    }

    public function test_experience_finance_filters_use_due_dates_and_preserve_global_balances(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-15 12:00:00'));
        try {
            $supplier = \App\Models\Supplier::create(['name'=>'Fornecedor filtro']);
            $sale = Sale::create(['request_key'=>(string)Str::uuid(),'user_id'=>$this->user->id,'payment_method'=>'cash','paid'=>false,'total'=>'100','cost_total'=>'0']);
            $make = fn(array $overrides=[]) => FinancialEntry::create(array_merge(['request_key'=>(string)Str::uuid(),'user_id'=>$this->user->id,'sale_id'=>$sale->id,'supplier_id'=>$supplier->id,'direction'=>'in','description'=>'Título filtro','category'=>'Vendas','amount'=>'100','paid_amount'=>'20','due_date'=>'2026-11-10','created_at'=>'2026-12-01 00:00:00'], $overrides));
            $partial=$make(); $paid=$make(['paid_amount'=>'100','paid_at'=>now()]); $cancelled=$make(['cancelled_at'=>now()]);
            $out=$make(['direction'=>'out','amount'=>'50','paid_amount'=>'10']);
            $future=$make(['sale_id'=>null,'amount'=>'30','paid_amount'=>'0','due_date'=>'2026-12-10']);
            $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
            $query=['sale'=>$sale->id,'q'=>'Fornecedor filtro','direction'=>'in','status'=>'overdue','from'=>'2026-11-10','to'=>'2026-11-10'];
            $this->get('/app/a/finance?'.http_build_query($query))->assertOk()
                ->assertViewHas('entries',fn($rows)=>$rows->pluck('id')->all()===[$partial->id])
                ->assertViewHas('receivable',fn($v)=>bccomp((string)$v,'110.00',2)===0)
                ->assertViewHas('payable',fn($v)=>bccomp((string)$v,'40.00',2)===0);
            foreach (['paid'=>$paid->id,'cancelled'=>$cancelled->id] as $state=>$id) {
                $this->get('/app/a/finance?'.http_build_query(array_replace($query,['status'=>$state])))->assertOk()->assertViewHas('entries',fn($rows)=>$rows->pluck('id')->all()===[$id]);
            }
            $this->get('/app/a/finance?direction=out&status=pending')->assertOk()->assertViewHas('entries',fn($rows)=>$rows->pluck('id')->all()===[$out->id]);
            $this->get('/app/a/finance?from=2026-12-10&to=2026-12-10&status=pending')->assertOk()->assertViewHas('entries',fn($rows)=>$rows->pluck('id')->all()===[$future->id]);
            $this->getJson('/app/a/finance?from=2026-12-10&to=2026-11-10')->assertUnprocessable()->assertJsonValidationErrors('to');
        } finally { $this->travelBack(); }
    }

    public function test_experience_csv_crosses_500_row_boundary_without_duplicates_or_relation_query_explosion(): void
    {
        $this->product->update(['controls_stock'=>false]);
        $groupId=$this->product->product_group_id;
        $customer=\App\Models\Customer::create(['name'=>'Cliente exportação']);
        $products=[]; $sales=[]; $movements=[];
        for ($i=0;$i<501;$i++) {
            $products[]=['sku'=>sprintf('EXPORT-%04d',$i),'name'=>'Exportação '.$i,'product_group_id'=>$groupId,'unit'=>'UN','quantity'=>'1','average_cost'=>'2','sale_price'=>'3'];
            $sales[]=['request_key'=>(string)Str::uuid(),'customer_id'=>$customer->id,'user_id'=>$this->user->id,'payment_method'=>'cash','paid'=>true,'total'=>'3','cost_total'=>'2','created_at'=>'2026-11-10 12:00:00'];
        }
        DB::connection('tenant')->table('products')->insert($products);
        DB::connection('tenant')->table('sales')->insert($sales);
        $productIds=Product::where('controls_stock',true)->orderBy('id')->pluck('id')->all();
        $saleIds=Sale::orderBy('id')->pluck('id')->all();
        foreach ($productIds as $id) { $movements[]=['request_key'=>(string)Str::uuid(),'product_id'=>$id,'user_id'=>$this->user->id,'type'=>'in','quantity'=>'1','unit_cost'=>'2','balance'=>'1','average_cost'=>'2','reason'=>'Exportação','created_at'=>'2026-11-10 12:00:00']; }
        DB::connection('tenant')->table('stock_movements')->insert($movements);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $queries=0; $counting=false;
        DB::listen(function($event)use(&$queries,&$counting){ if($counting && preg_match('/^select\b/i',ltrim($event->sql))) { $queries++; } });
        foreach (['stock'=>'SKU','movements'=>'Data','sales'=>'Venda'] as $kind=>$header) {
            $queries=0; $counting=true;
            try { $csv=$this->get('/app/a/reports/export/'.$kind.'?from=2026-11-01&to=2026-11-30')->assertOk()->streamedContent(); }
            finally { $counting=false; }
            $rows=$this->csvRows($csv,$header); $data=array_slice($rows,1);
            $this->assertCount(501,$data,$kind);
            $this->assertLessThanOrEqual(25,$queries,$kind.' deve carregar relações por lote, não por linha.');
            if ($kind==='sales') {
                $this->assertSame(array_map('strval',$saleIds),array_column($data,0));
                $this->assertSame(['Cliente exportação'],array_values(array_unique(array_column($data,2))));
                $this->assertSame(['3.00'],array_values(array_unique(array_column($data,4))));
            } elseif ($kind==='stock') {
                $this->assertSame(array_column($products,'sku'),array_column($data,0));
                $this->assertSame(['Geral'],array_values(array_unique(array_column($data,2))));
            } else {
                $this->assertSame(array_column($products,'name'),array_column($data,1));
                $this->assertSame(['1.000'],array_values(array_unique(array_column($data,3))));
            }
        }
    }

    public function test_operator_cannot_mutate_catalog_or_stock(): void
    {
        $this->user->update(['role' => 'operator']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->post('/app/a/stock', [])->assertForbidden();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->get('/app/a/users')->assertForbidden();
    }

    public function test_unknown_or_inactive_tenant_is_denied(): void
    {
        $this->get('/app/not-found/login')->assertNotFound();
        $this->a->update(['active' => false]);
        $this->get('/app/a/login')->assertNotFound();
    }

    public function test_public_page_renders(): void
    {
        $this->get('/')->assertOk()->assertSee('vendra')->assertSee('https://wa.me/5535999193304', false)->assertSee('99919-3304')->assertSee('mailto:ivancasvilela@gmail.com', false)->assertSee('/app/vexo/login', false)->assertSee('Demonstração ilustrativa');
    }

    public function test_http_catalog_stock_sale_and_receipt_workflow(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->post('/app/a/business', ['legal_name' => 'Empresa A Teste', 'tax_regime' => 'simples', 'state' => 'SP', 'municipality_code' => '3550308'])->assertRedirect();
        $this->post('/app/a/catalog/groups', ['name' => 'Informática', 'margin' => '40'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('Empresa A Teste', \App\Models\BusinessProfile::findOrFail(1)->legal_name);
        $group = ProductGroup::where('name', 'Informática')->firstOrFail();
        $this->post('/app/a/products/save', ['name' => 'Mouse', 'sku' => 'MOUSE-1', 'product_group_id' => $group->id, 'unit' => 'UN', 'sale_price' => '100', 'minimum_stock' => '2', 'active' => 1])->assertRedirect('/app/a/products');
        app(TenantConnection::class)->connect($this->a);
        $product = Product::where('sku', 'MOUSE-1')->firstOrFail();
        $this->post('/app/a/stock', ['request_key' => (string) Str::uuid(), 'product_id' => $product->id, 'type' => 'in', 'quantity' => '10', 'unit_cost' => '60', 'reason' => 'Compra inicial'])->assertRedirect();
        $data = $this->saleData();
        $data['items'][0]['id'] = $product->id;
        $data['paid'] = false;
        $data['due_date'] = '2027-01-01';
        $response = $this->post('/app/a/sales', $data);
        $response->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $sale = Sale::firstOrFail();
        $this->assertSame('9.000', $product->fresh()->quantity);
        $this->get('/app/a/sales/'.$sale->id)->assertOk()->assertSee('Mouse');
        $this->post('/app/a/sales/'.$sale->id.'/receive')->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertTrue((bool) $sale->fresh()->paid);
        $this->post('/app/a/sales/'.$sale->id.'/cancel', ['refund_confirmed' => 1])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('10.000', $product->fresh()->quantity);
    }

    public function test_administrator_can_create_operator(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->post('/app/a/users', ['name' => 'Operador', 'email' => 'operator@example.test', 'role' => 'operator', 'password' => 'operator-pass-123', 'password_confirmation' => 'operator-pass-123'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, User::count());
    }

    public function test_quote_conversion_is_idempotent_and_stock_waits_for_sale(): void
    {
        $this->move('in', '5', '60');
        $data = $this->saleData('2');
        $data['type'] = 'quote';
        $d = app(Documents::class)->create($data, $this->user->id);
        $this->assertSame('5.000', $this->product->fresh()->quantity);
        // A separate sale may use the same request token in its own operation scope.
        $direct = $this->saleData('1');
        $direct['request_key'] = $data['request_key'];
        $directSale = app(Checkout::class)->complete($direct, $this->user->id);
        app(Documents::class)->transition($d->id, 'approved');
        $payment = ['payment_method' => 'pix', 'paid' => true];
        $sale = app(Documents::class)->invoice($d->id, $payment, $this->user->id);
        $this->assertSame($sale->id, app(Documents::class)->invoice($d->id, $payment, $this->user->id)->id);
        $this->assertNotSame($directSale->id, $sale->id);
        $this->assertSame(2, Sale::count());
        $this->assertSame('2.000', $this->product->fresh()->quantity);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->get('/app/a/documents/'.$d->id)->assertOk();
    }

    public function test_work_order_reception_edit_and_invoice_move_stock_and_finance_only_once(): void
    {
        $this->move('in', '5', '60');
        $customer = \App\Models\Customer::create(['name' => 'Cliente da recepção']);
        $service = Service::create(['name' => 'Mão de obra QA', 'price' => '50.00', 'cost' => '10.00']);
        $mappedService = Product::create(['name' => 'Mão de obra QA', 'sku' => 'OS-LEGACY-SERVICE', 'product_group_id' => $this->product->product_group_id, 'type' => 'service', 'controls_stock' => false, 'reference_cost' => '10.0000', 'sale_price' => '50.00', 'unit' => 'UN', 'legacy_service_id' => $service->id]);
        $documents = app(Documents::class);
        $native = fn () => [Sale::count(), FinancialEntry::count(), \App\Models\Payment::count(), StockMovement::count(), $this->product->fresh()->quantity];
        $before = $native();
        $payload = ['type' => 'work_order', 'request_key' => (string) Str::uuid(), 'customer_id' => $customer->id,
            'asset' => 'Notebook serial QA-ABC', 'technician' => 'Técnica responsável', 'notes' => 'Recebido para diagnóstico'];
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->post('/app/a/documents', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/app/a/documents', $payload)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $doc = \App\Models\Document::firstOrFail();
        $this->assertSame(1, \App\Models\Document::count());
        $this->assertSame('draft', $doc->status);
        $this->assertSame([], $doc->items);
        $this->assertSame('0.00', $doc->total);
        $this->assertSame($before, $native());
        try { $documents->transition($doc->id, 'approved'); $this->fail('OS vazia não pode ser aprovada.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('items', $exception->errors()); }
        $payment = ['payment_method' => 'invoice', 'paid' => false, 'installments' => 2, 'due_date' => now()->addMonth()->toDateString()];
        $doc->update(['status' => 'completed']);
        try { $documents->invoice($doc->id, $payment, $this->user->id); $this->fail('Mesmo uma OS legada concluída sem itens não pode gerar venda.'); }
        catch (ValidationException) { $this->assertSame($before, $native()); }
        $doc->update(['status' => 'draft']);
        $hash = $documents->editHash($doc->fresh());
        $edit = ['expected_hash' => $hash, 'customer_id' => $customer->id, 'asset' => $payload['asset'],
            'technician' => $payload['technician'], 'notes' => 'Diagnóstico concluído: substituir componente', 'items' => [
                ['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1.500', 'unit_price' => '100.00'],
                ['kind' => 'service', 'id' => $service->id, 'quantity' => '1', 'unit_price' => '50.00'],
            ]];
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertOk();
        $this->post('/app/a/documents/'.$doc->id.'/update', $edit)->assertRedirect('/app/a/documents/'.$doc->id)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('200.00', $doc->fresh()->total);
        $this->assertSame($before, $native());
        $this->postJson('/app/a/documents/'.$doc->id.'/update', array_replace($edit, ['notes' => 'Edição antiga que não pode sobrescrever']))->assertUnprocessable()->assertJsonValidationErrors('expected_hash');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($edit['notes'], $doc->fresh()->notes);
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertOk()->assertSee('data-legacy-service-id="'.$service->id.'"', false)->assertSee('value="product:'.$mappedService->id.'"', false);
        app(TenantConnection::class)->connect($this->a);
        foreach (['approved', 'in_progress', 'completed'] as $status) {
            $documents->transition($doc->id, $status);
            $this->assertSame($before, $native());
        }
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertStatus(409);
        app(TenantConnection::class)->connect($this->a);
        $sale = $documents->invoice($doc->id, $payment, $this->user->id);
        $this->assertSame($sale->id, $documents->invoice($doc->id, $payment, $this->user->id)->id);
        $this->assertSame('200.00', $sale->total);
        $this->assertSame('3.500', $this->product->fresh()->quantity);
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(['100.00', '100.00'], $sale->entries()->orderBy('installment_no')->pluck('amount')->all());
        $this->assertSame(0, \App\Models\Payment::count());
        $this->assertSame('invoiced', $doc->fresh()->status);
        try { $documents->updateDraft($doc->id, array_replace($edit, ['expected_hash' => $documents->editHash($doc->fresh())]), $this->user->id); $this->fail('OS faturada não pode ser editada.'); }
        catch (ValidationException) { $this->assertSame('200.00', $doc->fresh()->total); }
        try { $documents->create(['type' => 'quote', 'request_key' => (string) Str::uuid(), 'items' => []], $this->user->id); $this->fail('Orçamento continua exigindo itens.'); }
        catch (ValidationException) { $this->assertSame(1, \App\Models\Document::count()); }
    }

    public function test_work_order_starts_without_approval_and_can_be_edited_during_service(): void
    {
        $documents = app(Documents::class);
        $doc = $documents->create(['type'=>'work_order','request_key'=>(string) Str::uuid(),'items'=>[]], $this->user->id);
        $before = [Sale::count(), FinancialEntry::count(), StockMovement::count()];
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->get('/app/a/documents/'.$doc->id)->assertOk()->assertSee('Iniciar serviço')->assertDontSee('Registrar aprovação');
        $this->post('/app/a/documents/'.$doc->id.'/status', ['status'=>'completed'])->assertSessionHasErrors('status');
        $this->post('/app/a/documents/'.$doc->id.'/status', ['status'=>'in_progress'])->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('in_progress', $doc->fresh()->status);
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertOk();
        app(TenantConnection::class)->connect($this->a);
        $hash = $documents->editHash($doc->fresh());
        $this->post('/app/a/documents/'.$doc->id.'/update', ['expected_hash'=>$hash,'notes'=>'Diagnóstico durante o serviço','items'=>[]])->assertSessionHasNoErrors();
        $this->get('/app/a/documents/'.$doc->id)->assertOk()->assertSee('Finalizar serviço')->assertDontSee('Registrar aprovação');
        $this->post('/app/a/documents/'.$doc->id.'/status', ['status'=>'completed'])->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('completed', $doc->fresh()->status);
        $this->assertSame('Diagnóstico durante o serviço', $doc->fresh()->notes);
        $this->assertSame($before, [Sale::count(), FinancialEntry::count(), StockMovement::count()]);
        $this->get('/app/a/documents/'.$doc->id)->assertOk()->assertDontSee('id="document-checkout"', false);
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertStatus(409);
        app(TenantConnection::class)->connect($this->a);
        try { $documents->invoice($doc->id, ['payment_method'=>'cash','paid'=>true], $this->user->id); $this->fail('OS sem itens não gera venda.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('items', $e->errors()); }
        $legacy = $documents->create(['type'=>'work_order','request_key'=>(string) Str::uuid(),'items'=>[]], $this->user->id);
        $legacy->update(['status'=>'approved']);
        $documents->transition($legacy->id, 'in_progress');
        $this->assertSame('in_progress', $legacy->fresh()->status);
    }

    public function test_cash_next_opening_displays_last_counted_cash_even_after_days_without_activity(): void
    {
        $cash = app(\App\Services\CashService::class);
        $date = now()->subDays(3)->toDateString();
        $cash->close(['request_key'=>(string) Str::uuid(),'date'=>$date,'opening_balance'=>'120.00','counted_balance'=>'115.00'], $this->user->id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->get('/app/a/cash?opening_balance=999.00')->assertOk()
            ->assertSee('Saldo trazido do fechamento de')->assertSee('115,00')->assertSee('Confirmar abertura')
            ->assertViewHas('summary', fn($summary)=>$summary['opening_balance']==='115.00');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0, \App\Models\OperationRecord::where('action','cash_opened')->count());
        $this->post('/app/a/cash/open', ['date'=>now()->toDateString(),'opening_balance'=>'115.00'])->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('115.00', $cash->opening(now()->toDateString())->after['opening_balance']);
        $this->get('/app/a/cash')->assertOk()->assertDontSee('Saldo trazido do fechamento de');
    }

    public function test_work_order_document_filters_separate_type_status_customer_asset_and_id(): void
    {
        $customer = \App\Models\Customer::create(['name' => 'Cliente filtro QA', 'document' => '11222333000181', 'phone' => '35999990000']);
        $documents = app(Documents::class);
        $base = $this->saleData();
        $base['customer_id'] = $customer->id;
        $base['asset'] = 'QA-OS-ABC Notebook';
        $base['type'] = 'work_order';
        $draft = $documents->create($base, $this->user->id);
        $base['request_key'] = (string) Str::uuid();
        $approved = $documents->create($base, $this->user->id);
        $documents->transition($approved->id, 'approved');
        $base['request_key'] = (string) Str::uuid(); $base['type'] = 'quote';
        $quote = $documents->create($base, $this->user->id);
        $base['request_key'] = (string) Str::uuid(); $base['type'] = 'work_order'; $base['asset'] = 'Outro equipamento'; $base['customer_id'] = null;
        $documents->create($base, $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        foreach ([
            [['type' => 'work_order', 'status' => 'draft', 'q' => 'QA-OS-ABC'], [$draft->id]],
            [['type' => 'work_order', 'status' => 'approved', 'q' => 'Cliente filtro QA'], [$approved->id]],
            [['type' => 'work_order', 'status' => 'approved', 'q' => (string) $approved->id], [$approved->id]],
            [['type' => 'quote', 'q' => 'QA-OS-ABC'], [$quote->id]],
            [['type' => 'work_order', 'q' => '%'], []],
            [['type' => 'work_order', 'q' => "' OR 1=1 --"], []],
        ] as [$query, $ids]) {
            $this->get('/app/a/documents?'.http_build_query($query))->assertOk()
                ->assertViewHas('documents', fn ($page) => $page->pluck('id')->all() === $ids);
        }
        $this->get('/app/a/documents?type=unknown')->assertSessionHasErrors('type');
        $this->get('/app/a/documents?status=unknown')->assertSessionHasErrors('status');
        $this->get('/app/a/documents?q='.str_repeat('x', 101))->assertSessionHasErrors('q');
    }

    public function test_work_order_edit_preserves_seller_allows_operator_and_isolates_tenants(): void
    {
        $documents = app(Documents::class);
        $doc = $documents->create(['type' => 'work_order', 'request_key' => (string) Str::uuid(), 'items' => $this->saleData()['items'], 'asset' => 'OS do responsável original'], $this->user->id);
        $owner = [$doc->salesperson_id, $doc->seller_name];
        $operator = User::create(['name' => 'Técnico operador', 'email' => 'os-operator@example.test', 'password' => 'operator-password-test', 'role' => 'operator']);
        $operatorSeller = app(\App\Services\CommercialRules::class)->seller($operator->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $operator->id]);
        $this->get('/app/a/documents/'.$doc->id.'/edit')->assertOk()
            ->assertViewHas('initialItems', fn ($rows) => count($rows) === 1 && collect($rows)->every(fn ($line) => ! array_key_exists('commission_rate', $line) && ! array_key_exists('commission_amount', $line)));
        app(TenantConnection::class)->connect($this->a);
        $data = ['expected_hash' => $documents->editHash($doc->fresh()), 'items' => [], 'asset' => 'Recepção revisada pelo técnico', 'notes' => 'Aguardando diagnóstico'];
        $this->post('/app/a/documents/'.$doc->id.'/update', $data)->assertRedirect('/app/a/documents/'.$doc->id);
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($owner, [$doc->fresh()->salesperson_id, $doc->fresh()->seller_name]);
        $forged = array_replace($data, ['expected_hash' => $documents->editHash($doc->fresh()), 'salesperson_id' => $operatorSeller->id]);
        $this->postJson('/app/a/documents/'.$doc->id.'/update', $forged)->assertUnprocessable()->assertJsonValidationErrors('salesperson_id');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($owner, [$doc->fresh()->salesperson_id, $doc->fresh()->seller_name]);
        $quoteData = $this->saleData(); $quoteData['type'] = 'quote';
        $quote = $documents->create($quoteData, $this->user->id);
        $this->get('/app/a/documents/'.$quote->id.'/edit')->assertStatus(409);
        app(TenantConnection::class)->connect($this->a);
        try { $documents->updateDraft($quote->id, ['expected_hash' => $documents->editHash($quote), 'items' => []], $this->user->id); $this->fail('Edição de OS não pode apagar itens de orçamento.'); }
        catch (ValidationException) { $this->assertCount(1, $quote->fresh()->items); }
        $other = $this->b;
        app(TenantConnection::class)->connect($other);
        $userB = User::forceCreate(['id' => $operator->id, 'name' => 'Operador B', 'email' => 'os-b@example.test', 'password' => 'other-password-test', 'role' => 'operator']);
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $userB->id]);
        $this->get('/app/b/documents/'.$doc->id.'/edit')->assertNotFound();
        $this->postJson('/app/b/documents/'.$doc->id.'/update', $data)->assertNotFound();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('Recepção revisada pelo técnico', $doc->fresh()->asset);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_work_order_requires_completion_before_sale(): void
    {
        $this->move('in', '5', '60');
        $data = $this->saleData();
        $data['type'] = 'work_order';
        $d = app(Documents::class)->create($data, $this->user->id);
        try {
            app(Documents::class)->invoice($d->id, ['payment_method' => 'cash', 'paid' => true], $this->user->id);
            $this->fail('Unfinished order must be rejected');
        } catch (ValidationException) {
        }
        foreach (['in_progress', 'completed'] as $status) {
            app(Documents::class)->transition($d->id, $status);
        }
        $sale = app(Documents::class)->invoice($d->id, ['payment_method' => 'cash', 'paid' => true], $this->user->id);
        $this->assertSame('100.00', $sale->fresh()->total);
    }

    public function test_financial_entry_and_settlement_are_idempotent(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $data = ['request_key' => (string) Str::uuid(), 'direction' => 'out', 'description' => 'Aluguel', 'category' => 'Estrutura', 'amount' => '1000.00', 'due_date' => '2027-01-01'];
        $this->post('/app/a/finance', $data)->assertRedirect();
        $this->post('/app/a/finance', $data)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $e = FinancialEntry::firstOrFail();
        $this->assertSame(1,FinancialEntry::count());
        $this->post('/app/a/finance/'.$e->id.'/pay', ['request_key' => (string) Str::uuid(), 'amount' => '1000.00', 'paid_on' => now()->toDateString(), 'payment_method' => 'pix'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertNotNull($e->fresh()->paid_at);
    }

    public function test_sale_reversal_uses_historical_cost_after_new_purchase(): void
    {
        $this->move('in', '10', '10');
        $sale = app(Checkout::class)->complete($this->saleData('4'), $this->user->id);
        $this->move('in', '6', '30');
        $this->assertSame('20.0000', $this->product->fresh()->average_cost);

        app(Checkout::class)->cancel($sale->id, $this->user->id, true);

        $this->assertSame('16.000', $this->product->fresh()->quantity);
        $this->assertSame('17.5000', $this->product->fresh()->average_cost);
        $this->assertSame('10.0000', StockMovement::latest('id')->firstOrFail()->unit_cost);
    }

    public function test_later_insufficient_item_rolls_back_every_line(): void
    {
        $this->move('in', '10', '60');
        $other = Product::create(['name' => 'Sem estoque', 'sku' => 'SEM-ESTOQUE', 'product_group_id' => $this->product->product_group_id, 'sale_price' => '50', 'unit' => 'UN']);
        $data = $this->saleData('2');
        $data['items'][] = ['kind' => 'product', 'id' => $other->id, 'quantity' => '1', 'unit_price' => '50'];

        try {
            app(Checkout::class)->complete($data, $this->user->id);
            $this->fail('A venda deve rejeitar o item sem saldo.');
        } catch (ValidationException) {
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, \App\Models\SaleItem::count());
            $this->assertSame(1, StockMovement::count());
            $this->assertSame('10.000', $this->product->fresh()->quantity);
        }
    }

    public function test_invalid_tenant_removes_previous_connection_context(): void
    {
        $this->assertSame($this->a->db_name, config('database.connections.tenant.database'));
        $invalid = new Tenant(['name' => 'Inválida', 'active' => true, 'db_name' => 'vendra_central']);

        $rejected = false;
        try {
            app(TenantConnection::class)->connect($invalid);
        } catch (\RuntimeException) {
            $rejected = true;
        }
        $this->assertTrue($rejected, 'Não deve permitir conectar o banco central como empresa.');
        $this->assertNull(config('database.connections.tenant'));
    }

    public function test_stock_key_cannot_be_reused_for_different_operation(): void
    {
        $key = (string) Str::uuid();
        $this->move('in', '10', '10', $key);

        try {
            $this->move('in', '20', '10', $key);
            $this->fail('Uma chave repetida não deve aceitar outra quantidade.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_key', $e->errors());
            $this->assertSame('10.000', $this->product->fresh()->quantity);
            $this->assertSame(1, StockMovement::count());
        }
    }

    public function test_domain_rejects_invalid_stock_numbers_without_partial_writes(): void
    {
        foreach ([['0', '10'], ['-1', '10'], ['0.0001', '10'], ['1e2', '10'], ['1', '-0.00001'], ['1', '10.00001'], ['1000000000', '10']] as [$quantity, $cost]) {
            try {
                $this->move('in', $quantity, $cost);
                $this->fail('Quantidade/custo fora dos limites deve ser rejeitado.');
            } catch (ValidationException) {
                $this->assertSame(0, StockMovement::count());
                $this->assertSame('0.000', $this->product->fresh()->quantity);
            }
        }
    }

    public function test_domain_rejects_negative_service_quantity_and_sale_overflow(): void
    {
        $service = Service::create(['name' => 'Serviço', 'price' => '10', 'cost' => '0']);
        foreach ([['-1', '10'], ['1.0001', '10'], ['1', '10.001'], ['999999', '999999999.99']] as [$quantity, $price]) {
            $data = $this->saleData();
            $data['items'] = [['kind' => 'service', 'id' => $service->id, 'quantity' => $quantity, 'unit_price' => $price]];
            try {
                app(Checkout::class)->complete($data, $this->user->id);
                $this->fail('Venda fora dos limites deve ser rejeitada.');
            } catch (ValidationException) {
                $this->assertSame(0, Sale::count());
                $this->assertSame(0, \App\Models\SaleItem::count());
            }
        }
    }

    public function test_same_user_id_in_other_database_does_not_grant_access(): void
    {
        app(TenantConnection::class)->connect($this->b);
        $otherUser = User::forceCreate(['id' => $this->user->id, 'name' => 'Admin B', 'email' => 'admin-b@example.test', 'password' => 'other-company-password', 'role' => 'admin']);
        $this->assertSame($this->user->id, $otherUser->id);

        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])
            ->get('/app/b')
            ->assertRedirect('/app/b/login')
            ->assertSessionMissing('tenant_id')
            ->assertSessionMissing('user_id');
    }

    public function test_logout_and_inactive_users_cannot_keep_operational_access(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])
            ->post('/app/a/logout')->assertRedirect('/app/a/login')->assertSessionMissing('user_id');
        $this->get('/app/a/stock')->assertRedirect('/app/a/login');
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['active' => false]);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])
            ->get('/app/a')->assertRedirect('/app/a/login');
        $this->post('/app/a/login', ['email' => 'admin@example.test', 'password' => 'test-password-123'])
            ->assertSessionHasErrors('email');
    }

    public function test_operator_cannot_manage_finance_cancel_or_receive_sales(): void
    {
        $this->move('in', '10', '60');
        $sale = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        Product::create(['name' => 'Serviço de balcão', 'sku' => 'OP-SERVICE-QA', 'product_group_id' => $this->product->product_group_id, 'type' => 'service', 'controls_stock' => false, 'reference_cost' => '17.4300', 'sale_price' => '50.00', 'unit' => 'UN']);
        $this->user->update(['role' => 'operator']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a')->assertOk()->assertDontSee('Resultado bruto das vendas')->assertDontSee('Vendas concluídas · todo o período')->assertDontSee('sales-svg-title', false);
        $this->get('/app/a/products')->assertOk()->assertDontSee('Custo médio (R$)')->assertDontSee('60,0000');
        $this->get('/app/a/stock')->assertOk()->assertDontSee('<th class="num">Custo unitário</th>', false)->assertDontSee('60.0000');
        $this->get('/app/a/products?type=service')->assertOk()->assertSee('Serviço de balcão')->assertDontSee('Custo (R$)')->assertDontSee('17.43')->assertDontSee('17,4300');
        $this->get('/app/a/catalog/groups')->assertOk()->assertDontSee('Margem padrão (%)');
        $this->get('/app/a/finance')->assertForbidden();
        $this->get('/app/a/cash')->assertForbidden();
        $this->post('/app/a/cash/close', [])->assertForbidden();
        $this->get('/app/a/cashflow')->assertForbidden();
        $this->post('/app/a/finance', [])->assertForbidden();
        $this->post('/app/a/sales/'.$sale->id.'/cancel')->assertForbidden();
        $this->post('/app/a/sales/'.$sale->id.'/receive')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('completed', $sale->fresh()->status);
    }

    public function test_http_validation_rejects_margin_precision_and_unpaid_sale_without_due_date(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        foreach (['-1', '100', '99.999'] as $margin) {
            $this->post('/app/a/catalog/groups', ['name' => 'Inválido '.$margin, 'margin' => $margin])
                ->assertSessionHasErrors('margin');
        }
        $data = $this->saleData();
        $data['paid'] = '0';
        $this->post('/app/a/sales', $data)->assertSessionHasErrors('due_date');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0, Sale::count());
        $this->assertSame(1, ProductGroup::count());
    }

    public function test_concurrent_identical_sales_return_one_sale_and_one_stock_outgoing(): void
    {
        $this->move('in', '10', '60');
        $data = $this->saleData('2');
        $results = $this->race('sales', fn () => app(Checkout::class)->complete($data, $this->user->id)->id);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(1, FinancialEntry::count());
        $this->assertSame(1, \App\Models\Payment::count());
        $this->assertSame('8.000', $this->product->fresh()->quantity);
    }

    public function test_concurrent_identical_quotes_return_one_document_without_stock_changes(): void
    {
        $data = $this->saleData();
        $data['type'] = 'quote';
        $results = $this->race('documents', fn () => app(Documents::class)->create($data, $this->user->id)->id);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, \App\Models\Document::count());
        $this->assertSame(0, StockMovement::count());
    }


    public function test_first_password_change_requires_current_password_and_invalidates_other_sessions(): void
    {
        $this->user->update(['must_change_password' => true]);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get('/app/a')->assertRedirect('/app/a/account/password');
        $this->get('/app/a/account/password')->assertOk();

        $data = ['current_password' => 'wrong-current-password', 'password' => 'new-valid-password-123', 'password_confirmation' => 'new-valid-password-123'];
        $this->post('/app/a/account/password', $data)->assertSessionHasErrors('current_password');
        app(TenantConnection::class)->connect($this->a);
        $this->assertTrue((bool) $this->user->fresh()->must_change_password);
        $this->assertSame(0, (int) $this->user->fresh()->auth_version);

        $data['current_password'] = 'test-password-123';
        $this->post('/app/a/account/password', $data)->assertRedirect('/app/a')->assertSessionHas('auth_version', 1);
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse((bool) $this->user->fresh()->must_change_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($data['password'], $this->user->fresh()->password));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0])->get('/app/a')->assertRedirect('/app/a/login');
    }

    public function test_last_active_administrator_cannot_be_demoted_or_disabled(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $base = ['name' => $this->user->name, 'email' => $this->user->email, 'role' => 'admin', 'active' => '1'];
        foreach ([['role' => 'operator'], ['active' => '0']] as $change) {
            $this->post('/app/a/users/'.$this->user->id, array_merge($base, $change))->assertSessionHasErrors('role');
            app(TenantConnection::class)->connect($this->a);
            $this->assertSame('admin', $this->user->fresh()->role);
            $this->assertTrue($this->user->fresh()->active);
        }
        User::create(['name' => 'Outro administrador', 'email' => 'other-admin@example.test', 'password' => 'another-password-123', 'role' => 'admin']);
        $this->post('/app/a/users/'.$this->user->id, array_merge($base, ['role' => 'operator']))->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('operator', $this->user->fresh()->role);
        $this->assertSame(1, (int) $this->user->fresh()->auth_version);
        $this->get('/app/a')->assertRedirect('/app/a/login');
    }


    public function test_csv_streaming_scopes_dates_escapes_formulas_and_requires_administrator(): void
    {
        $this->product->update(['name' => '=SUM(1+1)']);
        $this->move('in', '10', '60');
        $older = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        $older->update(['created_at' => '2026-10-15 12:00:00']);
        $current = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        $current->update(['created_at' => '2026-11-12 12:00:00']);
        \App\Models\Payment::where('kind', 'payment')->update(['paid_on' => '2026-11-12']);
        $payment = \App\Models\Payment::where('kind', 'payment')->orderBy('id')->firstOrFail();
        $payment->update(['paid_on' => '2026-10-15']);
        $paymentId = $payment->id;
        $entryId = $payment->entry_id;
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);

        $stock = $this->get('/app/a/reports/export/stock?from=2020-01-01&to=2020-01-01')->assertOk();
        $stockCsv = $stock->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $stockCsv);
        $this->assertStringContainsString("'=SUM(1+1)", $stockCsv);
        $this->assertStringContainsString(';', $stockCsv);
        $this->assertStringContainsString('SKU-1', $stockCsv);
        $this->assertStringContainsString($this->a->name, $stockCsv);

        $sales = $this->get('/app/a/reports/export/sales?from=2026-11-01&to=2026-11-30')->assertOk();
        $rows = $this->csvRows($sales->streamedContent(), 'Venda');
        $this->assertCount(2, $rows);
        $this->assertSame((string) $current->id, $rows[1][0]);

        $payments = $this->get('/app/a/reports/export/payments?from=2026-10-01&to=2026-10-31')->assertOk();
        $rows = $this->csvRows($payments->streamedContent(), 'Baixa');
        $this->assertCount(2, $rows);
        $this->assertSame((string) $paymentId, $rows[1][0]);
        $this->assertSame((string) $entryId, $rows[1][2]);

        $this->get('/app/a/reports?from=2026-11-30&to=2026-11-01')->assertSessionHasErrors('to');
        $this->get('/app/a/reports?from=2020-01-01&to=2026-11-01')->assertSessionHasErrors('to');
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['role' => 'operator']);
        $this->get('/app/a/reports')->assertForbidden();
        $this->get('/app/a/reports/export/stock')->assertForbidden();
    }

    private function csvRows(string $csv, ?string $tableHeader = null): array
    {
        $lines = preg_split('/\r?\n/', rtrim(substr($csv, 3), "\r\n"));
        $rows = array_map(fn ($line) => str_getcsv($line, ';', '"', ''), $lines);
        if ($tableHeader !== null) {
            $position = null;
            foreach ($rows as $rowIndex => $row) {
                $column = array_search($tableHeader, $row, true);
                if ($column !== false) {
                    $position = [$rowIndex, $column];
                    break;
                }
            }
            $this->assertNotNull($position, 'Cabeçalho da tabela CSV não encontrado após identificação da empresa.');
            $rows = array_map(fn ($row) => array_slice($row, $position[1]), array_slice($rows, $position[0]));
        }
        return $rows;
    }


    public function test_purchase_updates_average_cost_and_payables_atomically_and_idempotently(): void
    {
        $supplier = \App\Models\Supplier::create(['name' => 'Fornecedor teste']);
        $data = [
            'request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'reference' => 'COMPRA-TESTE', 'purchased_on' => '2026-11-12',
            'payment_method' => 'invoice', 'paid' => false, 'installments' => 3, 'due_date' => '2027-01-31',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
                ['product_id' => $this->product->id, 'quantity' => '20', 'unit_cost' => '25'],
            ],
        ];
        $service = app(\App\Services\PurchaseService::class);
        $purchase = $service->receive($data, $this->user->id);
        $this->assertSame('600.00', $purchase->total);
        $this->assertSame('30.000', $this->product->fresh()->quantity);
        $this->assertSame('20.0000', $this->product->fresh()->average_cost);
        $this->assertFalse($purchase->paid);
        $entries = $purchase->entries()->orderBy('installment_no')->get();
        $this->assertSame(['200.00', '200.00', '200.00'], $entries->pluck('amount')->all());
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $entries->map(fn ($e) => $e->due_date->toDateString())->all());
        $this->assertSame(['out'], $entries->pluck('direction')->unique()->values()->all());
        $this->assertSame($purchase->id, $service->receive($data, $this->user->id)->id);
        $this->assertSame(1, \App\Models\Purchase::count());
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(3, FinancialEntry::count());

        $bad = $data;
        $bad['request_key'] = (string) Str::uuid();
        $bad['items'][1]['unit_cost'] = '-1';
        try {
            $service->receive($bad, $this->user->id);
            $this->fail('Compra inválida deve desfazer inclusive a primeira entrada.');
        } catch (ValidationException) {
            $this->assertSame(1, \App\Models\Purchase::count());
            $this->assertSame(2, \App\Models\PurchaseItem::count());
            $this->assertSame(2, StockMovement::count());
            $this->assertSame(3, FinancialEntry::count());
            $this->assertSame('30.000', $this->product->fresh()->quantity);
        }
        $paid = $data;
        $paid['request_key'] = (string) Str::uuid();
        $paid['paid'] = true;
        $paid['installments'] = 1;
        $paid['payment_method'] = 'pix';
        $paid['items'] = [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '20']];
        $paidPurchase = $service->receive($paid, $this->user->id);
        $this->assertTrue($paidPurchase->paid);
        $payment = \App\Models\Payment::firstOrFail();
        $this->assertSame('out', $payment->direction);
        $this->assertSame('20.00', $payment->amount);
        $this->assertSame('20.0000', $this->product->fresh()->average_cost);
    }

    public function test_discount_defaults_inheritance_overrides_and_combined_limit_are_enforced_atomically(): void
    {
        $this->move('in', '10', '40');
        $checkout = app(Checkout::class);
        $group = \App\Models\ProductGroup::findOrFail($this->product->product_group_id);
        $reject = function (string $price, string $percent) use ($checkout): void {
            $before = [Sale::count(), StockMovement::count(), $this->product->fresh()->quantity];
            $data = $this->saleData();
            $data['items'][0]['unit_price'] = $price;
            $data['items'][0]['discount_percent'] = $percent;
            try {
                $checkout->complete($data, $this->user->id);
                $this->fail('Desconto não autorizado deve ser rejeitado sem efeitos.');
            } catch (ValidationException) {
                $this->assertSame($before, [Sale::count(), StockMovement::count(), $this->product->fresh()->quantity]);
            }
        };
        $reject('99.99', '0');
        $reject('100', '0.01');
        $group->update(['discount_enabled' => true, 'max_discount_percent' => '10.00']);
        $data = $this->saleData();
        $data['items'][0]['discount_percent'] = '10.00';
        $this->assertSame('90.00', $checkout->complete($data, $this->user->id)->total);
        $reject('90', '1');
        $reject('89.99', '0');
        $this->product->update(['discount_enabled' => false]);
        $reject('100', '1');
        $this->product->update(['discount_enabled' => true, 'max_discount_percent' => '20.00']);
        $data = $this->saleData();
        $data['items'][0]['unit_price'] = '90.00';
        $data['items'][0]['discount_percent'] = '10.00';
        $this->assertSame('81.00', $checkout->complete($data, $this->user->id)->total);
        $this->product->update(['discount_enabled' => null, 'max_discount_percent' => null]);
        $reject('90', '1');
    }

    public function test_commission_snapshots_use_discounted_cents_and_authorized_seller(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '1.0000', 'sale_price' => '10.00']);
        ProductGroup::findOrFail($this->product->product_group_id)->update(['discount_enabled' => true,
            'max_discount_percent' => '10.00', 'commission_percent' => '7.50']);
        $seller = User::create(['name' => 'Vendedor comissionado', 'email' => 'seller@example.test',
            'password' => 'seller-password-test', 'role' => 'operator', 'active' => true]);
        $data = $this->saleData('0.333');
        $data['seller_id'] = $seller->id;
        $data['items'][0]['unit_price'] = '10.00';
        $data['items'][0]['discount_percent'] = '10.00';
        $checkout = app(Checkout::class);
        $sale = $checkout->complete($data, $this->user->id);
        $line = $sale->items()->firstOrFail();
        $this->assertSame('3.00', $sale->total);
        $this->assertSame($seller->id, $sale->seller_id);
        $this->assertSame('Vendedor comissionado', $sale->seller_name);
        $this->assertSame('7.50', $line->commission_rate);
        $this->assertSame('0.23', $line->commission_amount);
        $this->assertSame('0.33', $line->discount_amount);
        $snapshot = $line->getAttributes();
        ProductGroup::findOrFail($this->product->product_group_id)->update(['commission_percent' => '50.00']);
        $seller->update(['name' => 'Nome alterado']);
        $this->assertSame($snapshot, $line->fresh()->getAttributes());
        $this->assertSame('Vendedor comissionado', $sale->fresh()->seller_name);
        $this->assertSame($sale->id, $checkout->complete($data, $this->user->id)->id);
        $this->assertSame(1, Sale::count());
        foreach ([$this->user->id, $seller->id] as $requested) {
            if ($requested === $seller->id) {
                $seller->update(['active' => false]);
                \App\Models\Seller::where('user_id', $seller->id)->update(['active' => false]);
                $actor = $this->user->id;
            } else {
                $actor = $seller->id;
            }
            $attempt = $data;
            $attempt['request_key'] = (string) Str::uuid();
            $attempt['seller_id'] = $requested;
            try {
                $checkout->complete($attempt, $actor);
                $this->fail('Vendedor forjado ou inativo deve ser rejeitado.');
            } catch (ValidationException) {
                $this->assertSame(1, Sale::count());
            }
        }
    }

    public function test_stale_catalog_price_and_untrusted_historical_flags_cannot_authorize_discount(): void
    {
        $this->move('in', '5', '40');
        $data = $this->saleData();
        $data['items'][0]['catalog_price_seen'] = '99.00';
        foreach ([$data, array_replace_recursive($this->saleData(), ['trusted' => true, 'historical' => true,
            'items' => [['unit_price' => '1.00', 'trusted' => true, 'historical' => true, 'approved_snapshot' => true]]])] as $attempt) {
            try {
                app(Checkout::class)->complete($attempt, $this->user->id);
                $this->fail('Preço desatualizado ou desconto forjado deve ser rejeitado.');
            } catch (ValidationException) {
                $this->assertSame(0, Sale::count());
                $this->assertSame('5.000', $this->product->fresh()->quantity);
                $this->assertSame(1, StockMovement::count());
            }
        }
    }

    public function test_commission_report_uses_sale_date_cumulative_receipts_and_excludes_cancellations(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '20.0000']);
        ProductGroup::findOrFail($this->product->product_group_id)->update(['commission_percent' => '10.00']);
        $data = $this->saleData();
        $data['paid'] = false;
        $data['due_date'] = now()->toDateString();
        $checkout = app(Checkout::class);
        $sale = $checkout->complete($data, $this->user->id);
        $saleDate = now()->subDays(2)->toDateString();
        $sale->update(['created_at' => $saleDate.' 10:00:00']);
        $paymentKey = (string) Str::uuid();
        $entry = $sale->entries()->firstOrFail();
        $finance = app(\App\Services\FinancialService::class);
        $finance->pay($entry->id, '25.00', now()->toDateString(), 'pix', $this->user->id, $paymentKey);
        $finance->pay($entry->id, '25.00', now()->toDateString(), 'pix', $this->user->id, $paymentKey);
        $data['request_key'] = (string) Str::uuid();
        $cancelled = $checkout->complete($data, $this->user->id);
        $cancelled->update(['created_at' => $saleDate.' 11:00:00']);
        $checkout->cancel($cancelled->id, $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a/commissions?from='.$saleDate.'&to='.$saleDate)->assertOk()
            ->assertViewHas('totals', fn ($totals) => $totals === ['net_sales' => '100.00', 'discounts' => '0.00',
                'commission' => '10.00', 'eligible' => '2.50', 'awaiting' => '7.50']);
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['role' => 'operator']);
        $this->get('/app/a/commissions')->assertForbidden();
    }

    public function test_document_conversion_honors_server_snapshots_and_legacy_prices_with_current_commission(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '10.0000']);
        $group = ProductGroup::findOrFail($this->product->product_group_id);
        $group->update(['discount_enabled' => true, 'max_discount_percent' => '10.00', 'commission_percent' => '5.00']);
        $data = $this->saleData();
        $data['type'] = 'quote';
        $data['items'][0]['discount_percent'] = '10.00';
        $documents = app(Documents::class);
        $document = $documents->create($data, $this->user->id);
        $this->assertSame('90.00', $document->total);
        $documents->transition($document->id, 'approved');
        $legacy = \App\Models\Document::create(['request_key' => (string) Str::uuid(), 'type' => 'quote',
            'status' => 'approved', 'user_id' => $this->user->id, 'commercial_version' => 0, 'total' => '60.00',
            'items' => [['kind' => 'product', 'id' => $this->product->id, 'description' => 'Preço histórico aprovado',
                'quantity' => '1.000', 'unit_price' => '60.00', 'total' => '60.00']]]);
        $this->product->update(['sale_price' => '200.00']);
        $group->update(['discount_enabled' => false, 'max_discount_percent' => '0.00', 'commission_percent' => '20.00']);
        $payment = ['payment_method' => 'pix', 'paid' => false, 'due_date' => now()->toDateString()];
        $sale = $documents->invoice($document->id, $payment, $this->user->id);
        $oldSale = $documents->invoice($legacy->id, $payment + ['seller_id' => $this->user->id], $this->user->id);
        $this->assertSame('90.00', $sale->total);
        $this->assertSame('18.00', $sale->commission_total);
        $this->assertSame('60.00', $oldSale->total);
        $this->assertSame('12.00', $oldSale->commission_total);
        $this->assertSame('0.00', $oldSale->discount_total);
        $this->assertSame($sale->id, $documents->invoice($document->id, $payment, $this->user->id)->id);
        $this->assertSame(2, Sale::count());
        $forged = $this->saleData();
        $forged['items'][0]['unit_price'] = '60.00';
        $forged['commercial_version'] = 0;
        $forged['document_id'] = $legacy->id;
        try {
            app(Checkout::class)->complete($forged, $this->user->id);
            $this->fail('Payload público não pode reivindicar preço histórico de documento.');
        } catch (ValidationException) {
            $this->assertSame(2, Sale::count());
        }
    }

    public function test_dashboard_monthly_year_selection_excludes_cancelled_sales_and_fills_empty_months(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:00:00'));
        try {
            $this->product->update(['controls_stock' => false, 'reference_cost' => '10.0000']);
            foreach ([['2025-01-10', '100.00', false], ['2025-12-20', '200.00', false],
                ['2025-01-15', '999.00', true], ['2026-02-10', '500.00', false]] as [$date, $price, $cancelled]) {
                $data = $this->saleData();
                $data['paid'] = false;
                $data['due_date'] = '2026-12-10';
                $data['items'][0]['unit_price'] = $price;
                $sale = app(Checkout::class)->complete($data, $this->user->id);
                $sale->update(['created_at' => $date.' 12:00:00']);
                if ($cancelled) {
                    app(Checkout::class)->cancel($sale->id, $this->user->id);
                }
            }
            $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
            $this->get('/app/a')->assertOk()->assertViewHas('salesYear', 2025)
                ->assertViewHas('salesPeriodCount', 2)
                ->assertViewHas('salesPeriodTotal', fn ($value) => bccomp((string) $value, '300.00', 2) === 0)
                ->assertViewHas('salesTrend', function ($rows) {
                    return count($rows) === 12 && array_column($rows, 'label') === ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez']
                        && array_column($rows, 'total') === array_merge(['100.00'], array_fill(0, 10, '0.00'), ['200.00'])
                        && array_column($rows, 'count') === array_merge([1], array_fill(0, 10, 0), [1]);
                });
            $this->get('/app/a?year=2026')->assertOk()->assertViewHas('salesYear', 2026)
                ->assertViewHas('salesPeriodCount', 1)
                ->assertViewHas('salesPeriodTotal', fn ($value) => bccomp((string) $value, '500.00', 2) === 0)
                ->assertViewHas('salesTrend', fn ($rows) => $rows[1]['total'] === '500.00' && $rows[1]['count'] === 1);
            $this->get('/app/a?year=2027')->assertSessionHasErrors('year');
        } finally {
            $this->travelBack();
        }
    }

    public function test_independent_seller_without_login_retains_credit_separate_from_executor(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '10.0000']);
        ProductGroup::findOrFail($this->product->product_group_id)->update(['commission_percent' => '10.00']);
        $seller = \App\Models\Seller::create(['name' => 'Consultora sem login', 'active' => true]);
        $data = $this->saleData();
        $data['salesperson_id'] = $seller->id;
        $sale = app(Checkout::class)->complete($data, $this->user->id);
        $this->assertSame($seller->id, $sale->salesperson_id);
        $this->assertNull($sale->seller_id);
        $this->assertSame($this->user->id, $sale->user_id);
        $this->assertSame('Consultora sem login', $sale->seller_name);
        $this->assertSame('10.00', $sale->commission_total);
        $seller->update(['name' => 'Nome revisado', 'active' => false]);
        $this->assertSame('Consultora sem login', $sale->fresh()->seller_name);
        $this->assertSame($sale->id, app(Checkout::class)->complete($data, $this->user->id)->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a/commissions?salesperson_id='.$seller->id)->assertOk()
            ->assertViewHas('totals', fn ($totals) => $totals['commission'] === '10.00');
    }

    public function test_document_seller_carries_to_invoice_and_reassignment_is_authorized_and_audited(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '10.0000']);
        $first = \App\Models\Seller::create(['name' => 'Dona do orçamento', 'active' => true]);
        $next = \App\Models\Seller::create(['name' => 'Nova responsável', 'active' => true]);
        $executor = User::create(['name' => 'Executor', 'email' => 'executor@example.test', 'password' => 'executor-password-test', 'role' => 'operator']);
        $data = $this->saleData(); $data['type'] = 'quote'; $data['salesperson_id'] = $first->id;
        $documents = app(Documents::class);
        $assignment = app(\App\Services\SellerAssignment::class);
        $doc = $documents->create($data, $this->user->id);
        $documents->transition($doc->id, 'approved');
        $payment = ['payment_method' => 'pix', 'paid' => false, 'due_date' => now()->toDateString()];
        $reject = function (callable $action) use ($doc): void {
            try { $action(); $this->fail('Atribuição inválida deve ser rejeitada.'); }
            catch (ValidationException) { $this->assertNull($doc->fresh()->sale_id); }
        };
        $reject(fn () => $documents->invoice($doc->id, $payment + ['salesperson_id' => $next->id], $executor->id));
        $reject(fn () => $assignment->assign($doc->id, $executor->id, $next->id, 'Tentativa de transferência'));
        $reject(fn () => $assignment->assign($doc->id, $this->user->id, $next->id));
        $first->update(['active' => false]);
        $reject(fn () => $documents->invoice($doc->id, $payment, $executor->id));
        $assignment->assign($doc->id, $this->user->id, $next->id, 'Transferência autorizada');
        $assignment->assign($doc->id, $this->user->id, $next->id, 'Repetição sem alteração');
        $logs = $doc->assignments()->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($first->id, $logs->last()->previous_seller_id);
        $this->assertSame($next->id, $logs->last()->new_seller_id);
        $this->assertSame($this->user->id, $logs->last()->actor_id);
        $this->assertSame('Transferência autorizada', $logs->last()->reason);
        $sale = $documents->invoice($doc->id, $payment, $executor->id);
        $this->assertSame($next->id, $sale->salesperson_id);
        $this->assertSame($executor->id, $sale->user_id);
        $this->assertSame('Nova responsável', $sale->seller_name);
        try { $assignment->assign($doc->id, $this->user->id, $first->id, 'Depois da venda'); $this->fail('Faturado não pode ser reatribuído.'); }
        catch (ValidationException) { $this->assertSame($next->id, $sale->fresh()->salesperson_id); }
    }

    public function test_seller_migration_backfills_only_explicit_legacy_sales_without_changing_snapshots(): void
    {
        app(TenantConnection::class)->connect($this->b);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        $migration = require database_path('tenant-migrations/2026_01_01_000013_create_seller_master.php');
        $restored = false;
        try {
            $migration->down();
            $actor = User::create(['name' => 'Autor legado', 'email' => 'migration@example.test', 'password' => 'migration-password-test', 'role' => 'admin']);
            $base = ['user_id' => $actor->id, 'payment_method' => 'pix', 'paid' => false, 'total' => '100.00', 'cost_total' => '20.0000', 'subtotal' => '100.00', 'commission_total' => '7.00', 'created_at' => '2025-03-01 10:00:00', 'updated_at' => '2025-03-01 10:00:00'];
            DB::table('sales')->insert($base + ['request_key' => (string) Str::uuid(), 'seller_id' => $actor->id, 'seller_name' => 'Nome histórico']);
            DB::table('sales')->insert($base + ['request_key' => (string) Str::uuid(), 'seller_id' => null, 'seller_name' => null]);
            $doc = \App\Models\Document::create(['request_key' => (string) Str::uuid(), 'type' => 'quote', 'user_id' => $actor->id, 'items' => [], 'total' => '50.00']);
            $before = DB::table('sales')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $migration->up(); $restored = true;
            $after = DB::table('sales')->orderBy('id')->get()->map(function ($row) { $row = (array) $row; unset($row['salesperson_id']); return $row; })->all();
            $this->assertSame($before, $after);
            $profile = \App\Models\Seller::where('user_id', $actor->id)->firstOrFail();
            $rows = Sale::orderBy('id')->get();
            $this->assertSame($profile->id, $rows[0]->salesperson_id);
            $this->assertNull($rows[1]->salesperson_id);
            $this->assertNull($doc->fresh()->salesperson_id);
            $this->assertSame(0, \App\Models\SellerAssignment::count());
        } finally {
            if (! $restored) { $migration->up(); }
            DB::setDefaultConnection($previous);
            app(TenantConnection::class)->connect($this->a);
        }
    }

    public function test_sales_desk_scopes_operator_and_prefills_admin_seller_and_customer(): void
    {
        $this->product->update(['controls_stock' => false, 'reference_cost' => '10.0000']);
        $operator = User::create(['name' => 'Balcão', 'email' => 'desk@example.test', 'password' => 'desk-password-test', 'role' => 'operator']);
        $own = app(\App\Services\CommercialRules::class)->seller($operator->id);
        $other = \App\Models\Seller::create(['name' => 'Externa sem login', 'active' => true]);
        $customer = \App\Models\Customer::create(['name' => 'Cliente procurado', 'email' => 'busca@example.test']);
        \App\Models\Customer::create(['name' => 'Cliente diferente']);
        foreach ([$own->id, $other->id] as $id) {
            $data = $this->saleData(); $data['salesperson_id'] = $id;
            app(Checkout::class)->complete($data, $this->user->id);
            $data['request_key'] = (string) Str::uuid(); $data['type'] = 'quote';
            app(Documents::class)->create($data, $this->user->id);
        }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $operator->id]);
        $this->get('/app/a/desk?q=procurado')->assertOk()
            ->assertViewHas('metrics', fn ($metrics) => $metrics['sales_count'] === 1 && $metrics['quotes_count'] === 1 && bccomp($metrics['sales_total'], '100.00', 2) === 0)
            ->assertViewHas('myQuotes', fn ($rows) => $rows->every(fn ($doc) => $doc->salesperson_id === $own->id))
            ->assertViewHas('customerMatches', fn ($rows) => $rows->modelKeys() === [$customer->id]);
        $this->get('/app/a/desk?salesperson_id='.$other->id)->assertForbidden();
        $this->get('/app/a/sales/new?salesperson_id='.$other->id)->assertSessionHasErrors('salesperson_id');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $query = '?salesperson_id='.$other->id.'&customer_id='.$customer->id;
        $this->get('/app/a/desk'.$query)->assertOk()->assertViewHas('salesperson', fn ($seller) => $seller->id === $other->id);
        foreach (['sales/new', 'documents/new'] as $path) {
            $this->get('/app/a/'.$path.$query)->assertOk()->assertViewHas('selectedSalespersonId', $other->id)->assertViewHas('selectedCustomerId', (string) $customer->id);
        }
    }

    private function accessProfile(array $permissions, string $name = 'Perfil QA'): string
    {
        $service = app(\App\Access\AccessProfiles::class);
        return $service->save(['version'=>$service->state(true)['version'],'name'=>$name,'permissions'=>$permissions],$this->user->id);
    }

    private function profiledOperator(string $profile): User
    {
        return DB::connection('tenant')->transaction(function () use ($profile) {
            $user=User::create(['name'=>'Operador de perfil','email'=>'profile-'.Str::uuid().'@example.test','password'=>'test-profile-password','role'=>'operator','active'=>true]);
            app(\App\Access\AccessProfiles::class)->assign($user->id,'operator',$profile,$this->user->id);
            return $user;
        });
    }

    public function test_access_profiles_default_matches_legacy_and_configuration_changes_preserve_profiles(): void
    {
        $operator=User::create(['name'=>'Operador legado','email'=>'legacy-profile@example.test','password'=>'test-profile-password','role'=>'operator','active'=>true]);
        $service=app(\App\Access\AccessProfiles::class);
        $this->assertTrue($service->allows($operator,'sales.create'));
        $this->assertFalse($service->allows($operator,'finance.manage'));
        $id=$this->accessProfile(['finance.view'],'Consulta financeira');
        $this->profiledOperator($id);
        $before=$service->state(true);
        app(\App\Services\CompanyOperations::class)->save($this->operationPayload(['version'=>$before['version'],'commissions'=>false]),$this->user->id);
        $after=$service->state(true);
        $this->assertSame($before['profiles'],$after['profiles']);
        $this->assertSame($before['assignments'],$after['assignments']);
        $this->assertFalse(app(\App\Services\CompanyOperations::class)->current()['commissions']);
    }

    public function test_access_profiles_restrict_direct_urls_posts_and_navigation_for_read_only_finance(): void
    {
        $id=$this->accessProfile(['finance.view'],'Financeiro consulta');$operator=$this->profiledOperator($id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0]);
        $this->get('/app/a/finance')->assertOk()->assertDontSee('Registrar conta')->assertDontSee('Nova conta manual');
        $this->get('/app/a/desk')->assertForbidden();
        $this->get('/app/a/products')->assertForbidden();
        $this->get('/app/a/users')->assertForbidden();
        $this->get('/app/a/access-profiles')->assertForbidden();
        $this->post('/app/a/finance',[])->assertForbidden();
        $this->post('/app/a/finance/999/pay',[])->assertForbidden();
        $this->post('/app/a/sales',$this->saleData())->assertForbidden();
        $response=$this->get('/app/a/finance')->assertOk();
        $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());$xpath=new \DOMXPath($dom);
        $this->assertCount(1,$xpath->query('//nav[@aria-label="Menu da empresa"]//a[contains(@href,"/finance")]'));
        $this->assertCount(0,$xpath->query('//nav[@aria-label="Menu da empresa"]//a[contains(@href,"/desk")]'));
        $this->assertCount(0,$xpath->query('//form[@method="post"][not(contains(@action,"logout"))]'));
        app(TenantConnection::class)->connect($this->a);$this->assertSame(0,Sale::count());
    }

    public function test_access_profiles_can_grant_finance_actions_without_granting_admin_or_stock(): void
    {
        $id=$this->accessProfile(['finance.manage'],'Financeiro operação');$operator=$this->profiledOperator($id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0]);
        $this->get('/app/a/finance')->assertOk()->assertSee('Registrar conta');
        $data=['request_key'=>(string)Str::uuid(),'direction'=>'out','description'=>'Despesa QA perfil','category'=>'QA','amount'=>'25.00','due_date'=>now()->toDateString(),'installments'=>1];
        $this->post('/app/a/finance',$data)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);$this->assertSame(1,FinancialEntry::count());
        $entry=FinancialEntry::first();
        $this->post('/app/a/finance/'.$entry->id.'/pay',['request_key'=>(string)Str::uuid(),'amount'=>'25.00','paid_on'=>now()->toDateString(),'payment_method'=>'cash'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);$this->assertSame('25.00',FinancialEntry::find($entry->id)->paid_amount);
        $this->get('/app/a/users')->assertForbidden();
        $this->get('/app/a/setup')->assertForbidden();
        $this->post('/app/a/stock',[])->assertForbidden();
        app(TenantConnection::class)->connect($this->a);$this->assertSame('operator',User::find($operator->id)->role);
    }

    public function test_access_profiles_changes_apply_on_next_request_and_zero_permissions_cannot_leak_dashboard(): void
    {
        $id=$this->accessProfile(['products.view'],'Catálogo consulta');$operator=$this->profiledOperator($id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0]);
        $this->get('/app/a/products')->assertOk()->assertDontSee('Custo base')->assertDontSee('Novo cadastro');
        app(TenantConnection::class)->connect($this->a);$service=app(\App\Access\AccessProfiles::class);
        $service->save(['version'=>$service->state(true)['version'],'name'=>'Sem funções','permissions'=>[]],$this->user->id,$id);
        $this->get('/app/a/products')->assertForbidden();
        $this->get('/app/a')->assertOk()->assertDontSee('Últimas vendas')->assertDontSee('Repor estoque')->assertDontSee('Consultar catálogo');
        $this->get('/app/a/account/password')->assertOk();
    }

    public function test_access_profiles_reject_stale_unknown_escalation_and_in_use_deletion(): void
    {
        $id=$this->accessProfile(['sales.create'],'Caixa QA');$operator=$this->profiledOperator($id);
        app(TenantConnection::class)->connect($this->a);$service=app(\App\Access\AccessProfiles::class);$state=$service->state(true);
        foreach([
            ['version'=>0,'name'=>'Perfil antigo','permissions'=>[]],
            ['version'=>$state['version'],'name'=>'Perfil inválido','permissions'=>['administration']],
        ] as $input){try{$service->save($input,$this->user->id,$id);$this->fail('Perfil inválido aceito');}catch(ValidationException){$this->assertSame($state['profiles'],$service->state(true)['profiles']);}}
        try{$service->delete($id,$state['version'],$this->user->id);$this->fail('Perfil em uso excluído');}catch(ValidationException){$this->assertArrayHasKey($id,$service->state(true)['profiles']);}
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0])->post('/app/a/access-profiles',['version'=>$state['version'],'name'=>'Autoelevação','permissions'=>['fiscal.manage']])->assertForbidden();
        $this->get('/app/a/access-profiles')->assertForbidden();
    }

    public function test_access_profiles_permissions_and_assignments_are_isolated_between_companies(): void
    {
        $id=$this->accessProfile(['finance.view'],'Somente empresa A');$operator=$this->profiledOperator($id);
        app(TenantConnection::class)->connect($this->b);
        $other=User::create(['name'=>'Outro administrador','email'=>'profile-b@example.test','password'=>'test-profile-password','role'=>'admin','active'=>true]);
        $service=app(\App\Access\AccessProfiles::class);$this->assertArrayNotHasKey($id,$service->state(true)['profiles']);
        try{DB::connection('tenant')->transaction(fn()=>$service->assign($other->id,'operator',$id,$other->id));$this->fail('Perfil de outra empresa aceito');}catch(ValidationException){$this->assertArrayNotHasKey($id,$service->state(true)['profiles']);}
        app(TenantConnection::class)->connect($this->a);$this->assertSame($id,$service->profileId($operator->id));
        $this->assertTrue($service->allows(User::find($operator->id),'finance.view'));
    }

    public function test_access_profiles_editor_assignment_dependencies_and_audit_are_visible(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get('/app/a/access-profiles')->assertOk()->assertSee('Perfis e permissões')->assertDontSee('Curinga desconhecido');
        $this->post('/app/a/access-profiles',['version'=>0,'name'=>'Estoque supervisor','permissions'=>['products.manage','stock.manage']])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);$service=app(\App\Access\AccessProfiles::class);$state=$service->state(true);$id=array_key_last($state['profiles']);
        $this->assertContains('costs.view',$state['profiles'][$id]['permissions']);
        $this->assertContains('products.view',$state['profiles'][$id]['permissions']);
        $this->get('/app/a/access-profiles?profile='.$id)->assertOk()->assertSee('O que este perfil pode ver e fazer')->assertSee('Visualizar custos');
        $this->get('/app/a/users')->assertOk()->assertSee('Perfil operacional')->assertSee('Estoque supervisor');
        app(TenantConnection::class)->connect($this->a);$this->assertSame(1,DB::connection('tenant')->table('company_operation_audits')->where('action','access_profile_saved')->count());
    }

    public function test_access_profiles_can_delegate_fiscal_and_seller_functions_without_admin_role(): void
    {
        $this->product->update(['controls_stock'=>false]);
        $id=$this->accessProfile(['sales.create','sales.seller','documents.seller','fiscal.manage'],'Supervisor delegado');
        $operator=$this->profiledOperator($id);
        $other=\App\Models\Seller::create(['name'=>'Vendedor delegado','active'=>true]);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0]);
        $this->get('/app/a/desk?salesperson_id='.$other->id)->assertOk();
        $this->get('/app/a/fiscal')->assertOk();
        $this->get('/app/a/fiscal/company')->assertOk();
        $this->post('/app/a/business',[])->assertForbidden();
        $this->get('/app/a/users')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        app(\App\Fiscal\FiscalProfileManager::class)->authorize($this->a,$operator->id);
        $data=$this->saleData();$data['salesperson_id']=$other->id;
        $this->post('/app/a/sales',$data)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($other->id,Sale::first()->salesperson_id);
        $this->assertSame('operator',User::find($operator->id)->role);
    }

    public function test_access_profiles_cover_every_tenant_route_and_new_user_assignment(): void
    {
        foreach(app('router')->getRoutes() as $route){
            if(!in_array(\App\Http\Middleware\TenantAuthenticated::class,$route->middleware(),true))continue;
            $uri=preg_replace('/\{[^}]+\}/','1',$route->uri());$uri=str_replace('app/1','app/a',$uri);
            if(str_contains($route->uri(),'{kind}')){$uri=preg_replace('#catalog/1#','catalog/groups',$uri);$uri=preg_replace('#quick-create/1#','quick-create/customers',$uri);}
            $request=\Illuminate\Http\Request::create('/'.$uri,in_array('POST',$route->methods(),true)?'POST':'GET');
            $route=clone $route;$route->bind($request);$request->setRouteResolver(fn()=>$route);
            $permission=\App\Access\Permissions::request($request);
            $this->assertTrue(in_array($permission,array_merge(array_keys(\App\Access\Permissions::labels()),['administration','account.self','commissions.self','home']),true),$route->uri().' '.$route->getActionMethod());
        }
        $id=$this->accessProfile(['products.view'],'Consulta catálogo');
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->post('/app/a/users',['name'=>'Perfil vinculado','email'=>'new-profile@example.test','role'=>'operator','access_profile'=>$id,'password'=>'new-profile-password','password_confirmation'=>'new-profile-password'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);$user=User::where('email','new-profile@example.test')->firstOrFail();
        $this->assertSame($id,app(\App\Access\AccessProfiles::class)->profileId($user->id));
        $this->assertFalse($user->allows('sales.create'));
        $this->assertTrue($user->allows('products.view'));
    }

    public function test_pos_starts_with_items_and_accepts_anonymous_sale_with_idempotency(): void
    {
        $this->product->update(['controls_stock' => false, 'barcode' => '7891234567890']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $response = $this->get('/app/a/desk')->assertOk()
            ->assertSee('pos-search')->assertSee('checkout-form')->assertSee('pos-net-total')
            ->assertDontSee('Quem será atendido?')->assertDontSee('Inicie o atendimento');
        $dom = new \DOMDocument(); @$dom->loadHTML($response->getContent()); $xpath = new \DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//form[@id="checkout-form"]'));
        $this->assertCount(1, $xpath->query('//details[contains(@class,"pos-optionals") and not(@open)]//select[@name="customer_id"]'));
        $this->assertCount(1, $xpath->query('//input[@id="pos-search"][@autofocus]'));
        $this->assertCount(1, $xpath->query('//option[@data-barcode="7891234567890"]'));
        $data = $this->saleData();
        $this->post('/app/a/sales', $data)->assertRedirect();
        $this->post('/app/a/sales', $data)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Sale::count());
        $this->assertNull(Sale::first()->customer_id);
        $this->assertSame('100.00', Sale::first()->total);
    }

    public function test_pos_restores_cart_after_validation_error_and_filters_service_only_catalog(): void
    {
        $this->product->update(['active' => false]);
        $service = Product::create(['name' => 'Serviço balcão', 'sku' => 'PDV-SRV', 'type' => 'service', 'controls_stock' => false, 'sale_price' => '80.00', 'product_group_id' => $this->product->product_group_id]);
        app(\App\Services\CompanyOperations::class)->save($this->operationPayload(['catalog_mode' => 'services', 'inventory' => false, 'nfce' => false, 'nfe' => false]), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a/desk')->assertOk()->assertViewHas('products', fn ($items) => $items->modelKeys() === [$service->id]);
        $data = $this->saleData(); $data['items'] = [['kind' => 'product', 'id' => $service->id, 'quantity' => '2', 'unit_price' => '80.00']];
        $data['items_present'] = '1'; $data['paid'] = false; unset($data['due_date']);
        $this->from('/app/a/desk')->post('/app/a/sales', $data)->assertRedirect('/app/a/desk')->assertSessionHasErrors('due_date')->assertSessionHasInput('request_key', $data['request_key']);
        $response = $this->get('/app/a/desk')->assertOk()->assertSee($data['request_key']);
        $dom = new \DOMDocument(); @$dom->loadHTML($response->getContent());
        $this->assertCount(1, (new \DOMXPath($dom))->query('//select[@name="paid"]/option[@value="0"][@selected]'));
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0, Sale::count());
    }

    public function test_http_outstanding_metrics_sum_partial_balances_and_exclude_cancelled_entries(): void
    {
        $finance = app(\App\Services\FinancialService::class);
        foreach ([['in', '150.25', '40.10', false], ['out', '200.50', '50.20', false], ['in', '900.00', '0', true], ['out', '800.00', '0', true]] as [$direction, $amount, $paid, $cancelled]) {
            $entry = $finance->schedule(['request_key' => (string) Str::uuid(), 'direction' => $direction,
                'description' => 'Métrica de saldo', 'category' => 'Teste', 'amount' => $amount,
                'due_date' => now()->toDateString(), 'installments' => 1], $this->user->id)->first();
            if (bccomp($paid, '0', 2) > 0) {
                $finance->pay($entry->id, $paid, now()->toDateString(), 'pix', $this->user->id, (string) Str::uuid());
            }
            if ($cancelled) {
                $entry->update(['cancelled_at' => now()]);
            }
        }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a')->assertOk()
            ->assertViewHas('receivable', fn ($value) => bccomp((string) $value, '110.15', 2) === 0);
        $this->get('/app/a/finance')->assertOk()
            ->assertViewHas('receivable', fn ($value) => bccomp((string) $value, '110.15', 2) === 0)
            ->assertViewHas('payable', fn ($value) => bccomp((string) $value, '150.30', 2) === 0);
    }

    public function test_installments_preserve_total_original_day_and_partial_payment_history(): void
    {
        $finance = app(\App\Services\FinancialService::class);
        $data = ['request_key' => (string) Str::uuid(), 'direction' => 'out', 'description' => 'Contrato teste', 'category' => 'Compras', 'amount' => '100.01', 'due_date' => '2027-01-31', 'installments' => 3];
        $entries = $finance->schedule($data, $this->user->id);
        $this->assertSame(['33.33', '33.33', '33.35'], $entries->pluck('amount')->all());
        $this->assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], $entries->map(fn ($e) => $e->due_date->toDateString())->all());
        $this->assertSame($entries->modelKeys(), $finance->schedule($data, $this->user->id)->modelKeys());
        $entry = $entries->first();
        $key = (string) Str::uuid();
        $payment = $finance->pay($entry->id, '10', '2026-11-12', 'pix', $this->user->id, $key);
        $this->assertSame($payment->id, $finance->pay($entry->id, '10.00', '2026-11-12', 'pix', $this->user->id, $key)->id);
        $this->assertSame('10.00', $entry->fresh()->paid_amount);
        $this->assertSame('23.33', $entry->fresh()->balance);
        $this->assertNull($entry->fresh()->paid_at);
        foreach ([['24.00', '2026-11-12'], ['1.00', '2026-02-30']] as [$amount, $paidOn]) {
            try {
                $finance->pay($entry->id, $amount, $paidOn, 'pix', $this->user->id, (string) Str::uuid());
                $this->fail('Não deve aceitar excesso de pagamento ou data inválida.');
            } catch (ValidationException) {
                $this->assertSame(1, \App\Models\Payment::count());
                $this->assertSame('10.00', $entry->fresh()->paid_amount);
            }
        }
        $finance->pay($entry->id, '23.33', '2026-11-12', 'cash', $this->user->id, (string) Str::uuid());
        $this->assertSame('0.00', $entry->fresh()->balance);
        $this->assertNotNull($entry->fresh()->paid_at);
        $this->assertSame(2, \App\Models\Payment::count());
        $tooMany = $data;
        $tooMany['request_key'] = (string) Str::uuid();
        $tooMany['amount'] = '0.02';
        try {
            $finance->schedule($tooMany, $this->user->id);
            $this->fail('Não deve criar parcela menor que um centavo.');
        } catch (ValidationException) {
            $this->assertSame(3, FinancialEntry::count());
        }
    }

    public function test_sale_is_paid_only_after_all_installments_are_settled(): void
    {
        $this->move('in', '10', '60');
        $data = $this->saleData();
        $data['paid'] = false;
        $data['installments'] = 2;
        $data['due_date'] = '2027-01-31';
        $sale = app(Checkout::class)->complete($data, $this->user->id);
        $entries = $sale->entries()->orderBy('installment_no')->get();
        $finance = app(\App\Services\FinancialService::class);
        $finance->pay($entries[0]->id, '20.00', '2026-11-12', 'pix', $this->user->id, (string) Str::uuid());
        $this->assertFalse($sale->fresh()->paid);
        $finance->pay($entries[0]->id, '30.00', '2026-11-12', 'pix', $this->user->id, (string) Str::uuid());
        $this->assertFalse($sale->fresh()->paid);
        $finance->pay($entries[1]->id, '50.00', '2026-11-12', 'pix', $this->user->id, (string) Str::uuid());
        $this->assertTrue($sale->fresh()->paid);
        $this->assertSame('9.000', $this->product->fresh()->quantity);
    }

    public function test_paid_sale_requires_refund_confirmation_and_preserves_reversal_history(): void
    {
        $this->move('in', '10', '60');
        $sale = app(Checkout::class)->complete($this->saleData('2'), $this->user->id);
        $original = \App\Models\Payment::firstOrFail();
        try {
            app(Checkout::class)->cancel($sale->id, $this->user->id, false);
            $this->fail('Venda recebida exige confirmação de reembolso.');
        } catch (ValidationException) {
            $this->assertSame('completed', $sale->fresh()->status);
            $this->assertSame('8.000', $this->product->fresh()->quantity);
            $this->assertSame(1, \App\Models\Payment::count());
        }
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        $refund = \App\Models\Payment::where('kind', 'refund')->firstOrFail();
        $this->assertSame($original->id, $refund->reversal_of);
        $this->assertSame('out', $refund->direction);
        $this->assertSame('200.00', $refund->amount);
        $this->assertSame(2, \App\Models\Payment::count());
        $this->assertSame('10.000', $this->product->fresh()->quantity);
        $this->assertSame('0.00', $sale->entries()->firstOrFail()->balance);

        $legacyData = $this->saleData();
        $legacyData['paid'] = false;
        $legacyData['due_date'] = '2027-01-31';
        $legacy = app(Checkout::class)->complete($legacyData, $this->user->id);
        $legacy->entries()->firstOrFail()->update(['legacy_import' => true, 'legacy_paid_amount' => '100.00', 'paid_amount' => '100.00']);
        $legacy->update(['paid' => true]);
        try {
            app(Checkout::class)->cancel($legacy->id, $this->user->id, true);
            $this->fail('Recebimento legado sem histórico exige reconciliação.');
        } catch (ValidationException) {
            $this->assertSame('completed', $legacy->fresh()->status);
            $this->assertSame('9.000', $this->product->fresh()->quantity);
            $this->assertSame(2, \App\Models\Payment::count());
        }
    }


    public function test_cash_summary_separates_payment_methods_and_preserves_counted_opening(): void
    {
        $date = now()->subDay()->toDateString();
        $finance = app(\App\Services\FinancialService::class);
        $cash = app(\App\Services\CashService::class);
        $income = $finance->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'in', 'description' => 'Receita QA', 'category' => 'Teste', 'amount' => '150.00', 'due_date' => $date], $this->user->id)->first();
        $expense = $finance->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'out', 'description' => 'Despesa QA', 'category' => 'Teste', 'amount' => '40.00', 'due_date' => $date], $this->user->id)->first();
        $finance->pay($income->id, '100.00', $date, 'cash', $this->user->id, (string) Str::uuid());
        $finance->pay($income->id, '50.00', $date, 'pix', $this->user->id, (string) Str::uuid());
        $finance->pay($expense->id, '25.00', $date, 'cash', $this->user->id, (string) Str::uuid());
        $finance->pay($expense->id, '15.00', $date, 'debit', $this->user->id, (string) Str::uuid());
        $summary = $cash->summary($date, '200.00');
        $this->assertSame('100.00', $summary['cash_in']);
        $this->assertSame('25.00', $summary['cash_out']);
        $this->assertSame('275.00', $summary['expected_balance']);
        $this->assertSame(['in' => '100.00', 'out' => '25.00', 'net' => '75.00'], $summary['totals_by_method']['cash']);
        $this->assertSame('50.00', $summary['totals_by_method']['pix']['net']);
        $this->assertSame('-15.00', $summary['totals_by_method']['debit']['net']);
        $this->assertSame(4, $summary['payment_count']);
        $data = ['request_key' => (string) Str::uuid(), 'date' => $date, 'opening_balance' => '200.00', 'counted_balance' => '270.00', 'notes' => 'Diferença identificada na contagem QA'];
        $closure = $cash->close($data, $this->user->id);
        $this->assertSame('-5.00', $closure->difference);
        $this->assertSame($closure->id, $cash->close($data, $this->user->id)->id);
        $closedSummary = $cash->summary($date, '999.00');
        $this->assertTrue($closedSummary['is_closed']);
        $this->assertSame('200.00', $closedSummary['opening_balance']);
        $this->assertSame('275.00', $closedSummary['expected_balance']);
        try {
            $cash->close(array_merge($data, ['counted_balance' => '271.00']), $this->user->id);
            $this->fail('Uma chave repetida não pode alterar a contagem.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_key', $e->errors());
        }
        $next = ['request_key' => (string) Str::uuid(), 'date' => now()->toDateString(), 'opening_balance' => '999.00', 'counted_balance' => '270.00'];
        try {
            $cash->close($next, $this->user->id);
            $this->fail('A abertura deve continuar a contagem do dia anterior.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('opening_balance', $e->errors());
        }
        $next['opening_balance'] = '270.00';
        $nextClosure = $cash->close($next, $this->user->id);
        $this->assertSame('270.00', $nextClosure->expected_balance);
        try {
            $cash->close(array_merge($next, ['request_key' => (string) Str::uuid(), 'date' => now()->addDay()->toDateString()]), $this->user->id);
            $this->fail('Fechamento futuro deve ser rejeitado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
        }
        try {
            $closure->update(['notes' => 'Alteração indevida']);
            $this->fail('Fechamento não deve permitir edição via modelo.');
        } catch (\LogicException) {
            $this->assertSame('Diferença identificada na contagem QA', $closure->fresh()->notes);
        }
        $this->assertSame(2, \App\Models\CashClosure::count());
    }

    public function test_closed_cash_days_reject_backdating_refunds_and_paid_sales_atomically(): void
    {
        $finance = app(\App\Services\FinancialService::class);
        $cash = app(\App\Services\CashService::class);
        $older = now()->subDays(2)->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $entry = $finance->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'in', 'description' => 'Receita parcial QA', 'category' => 'Teste', 'amount' => '100.00', 'due_date' => $older], $this->user->id)->first();
        $paymentKey = (string) Str::uuid();
        $payment = $finance->pay($entry->id, '50.00', $older, 'cash', $this->user->id, $paymentKey);
        try {
            $cash->close(['request_key' => (string) Str::uuid(), 'date' => $yesterday, 'opening_balance' => '0.00', 'counted_balance' => '50.00'], $this->user->id);
            $this->fail('Não deve pular um dia anterior com movimento ainda aberto.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('date', $e->errors());
            $this->assertSame(0, \App\Models\CashClosure::count());
        }
        $cash->close(['request_key' => (string) Str::uuid(), 'date' => $older, 'opening_balance' => '0.00', 'counted_balance' => '50.00'], $this->user->id);
        $this->assertSame($payment->id, $finance->pay($entry->id, '50.00', $older, 'cash', $this->user->id, $paymentKey)->id);
        foreach ([$older, now()->subDays(3)->toDateString()] as $closedDate) {
            try {
                $finance->pay($entry->id, '10.00', $closedDate, 'pix', $this->user->id, (string) Str::uuid());
                $this->fail('Baixa nova retroativa não pode modificar período consolidado.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('paid_on', $e->errors());
                $this->assertSame(1, \App\Models\Payment::count());
            }
        }
        $cash->close(['request_key' => (string) Str::uuid(), 'date' => $yesterday, 'opening_balance' => '50.00', 'counted_balance' => '50.00'], $this->user->id);
        $this->move('in', '10', '60');
        $paidSale = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        $cash->close(['request_key' => (string) Str::uuid(), 'date' => now()->toDateString(), 'opening_balance' => '50.00', 'counted_balance' => '50.00'], $this->user->id);
        try {
            app(Checkout::class)->cancel($paidSale->id, $this->user->id, true);
            $this->fail('Estorno financeiro deve respeitar o fechamento de hoje.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('paid_on', $e->errors());
            $this->assertSame('completed', $paidSale->fresh()->status);
            $this->assertSame('9.000', $this->product->fresh()->quantity);
        }
        $before = [Sale::count(), \App\Models\SaleItem::count(), StockMovement::count(), FinancialEntry::count(), \App\Models\Payment::count()];
        try {
            app(Checkout::class)->complete($this->saleData('2'), $this->user->id);
            $this->fail('Venda já recebida deve falhar se a baixa cair em dia fechado.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('paid_on', $e->errors());
            $this->assertSame($before, [Sale::count(), \App\Models\SaleItem::count(), StockMovement::count(), FinancialEntry::count(), \App\Models\Payment::count()]);
            $this->assertSame('9.000', $this->product->fresh()->quantity);
        }
        $unpaid = $this->saleData('2');
        $unpaid['paid'] = false;
        $unpaid['due_date'] = now()->addMonth()->toDateString();
        $sale = app(Checkout::class)->complete($unpaid, $this->user->id);
        $this->assertSame('7.000', $this->product->fresh()->quantity);
        app(Checkout::class)->cancel($sale->id, $this->user->id);
        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame('9.000', $this->product->fresh()->quantity);
        try {
            app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
            $this->fail('Não deve misturar dados de demonstração com uma operação existente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('demo', $e->errors());
            $this->assertSame(1, Product::count());
            $this->assertSame(0, \App\Models\DemoRun::count());
        }
    }

    public function test_concurrent_payment_and_cash_close_leave_a_consistent_snapshot(): void
    {
        $date = now()->subDay()->toDateString();
        $entry = app(\App\Services\FinancialService::class)->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'in', 'description' => 'Corrida caixa QA', 'category' => 'Teste', 'amount' => '50.00', 'due_date' => $date], $this->user->id)->first();
        $paymentKey = (string) Str::uuid();
        $closeKey = (string) Str::uuid();
        $results = $this->race(null, function () use ($entry, $date, $paymentKey) {
            try {
                app(\App\Services\FinancialService::class)->pay($entry->id, '50.00', $date, 'cash', $this->user->id, $paymentKey);
                return true;
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('paid_on', $e->errors());
                return false;
            }
        }, fn () => app(\App\Services\CashService::class)->close(['request_key' => $closeKey, 'date' => $date, 'opening_balance' => '0.00', 'counted_balance' => '0.00'], $this->user->id)->id);
        $closure = \App\Models\CashClosure::findOrFail($results[1]);
        $this->assertSame(1, \App\Models\CashClosure::count());
        $this->assertSame($results[0] ? 1 : 0, \App\Models\Payment::count());
        $expected = $results[0] ? '50.00' : '0.00';
        $this->assertSame($expected, $closure->cash_in);
        $this->assertSame($expected, $closure->expected_balance);
        $this->assertSame(\App\Models\Payment::count(), $closure->snapshot['payment_count']);
    }


    public function test_computer_store_demo_is_additive_idempotent_and_reconciles_stock_and_finance(): void
    {
        $this->product->update(['name' => 'Produto real preservado pelo QA']);
        $original = $this->product->fresh()->getAttributes();
        $service = app(\App\Services\DemoComputerStore::class);
        $summary = $service->seed($this->user->id);
        $this->assertSame(now()->toDateString(), $summary['anchor_date']);
        $this->assertSame($original, $this->product->fresh()->getAttributes());
        $this->assertSame(1, User::count());
        $this->assertGreaterThan(0, Product::where('sku', 'like', 'DEMO-%')->count());
        $this->assertGreaterThan(0, \App\Models\Purchase::count());
        $this->assertGreaterThan(0, Sale::where('status', 'completed')->count());
        $this->assertGreaterThan(0, Product::where('type', 'service')->count());
        $this->assertGreaterThan(0, FinancialEntry::where('due_date', '>', now()->toDateString())->whereNull('cancelled_at')->count());
        $this->assertGreaterThan(0, \App\Models\CashClosure::count());
        $this->assertFalse(\App\Models\CashClosure::where('date', now()->toDateString())->exists());

        foreach (Product::orderBy('id')->get() as $product) {
            $balance = '0.000';
            foreach (StockMovement::where('product_id', $product->id)->orderBy('id')->get() as $movement) {
                $balance = in_array($movement->type, ['in', 'return'], true)
                    ? bcadd($balance, $movement->quantity, 3)
                    : bcsub($balance, $movement->quantity, 3);
                $this->assertSame($balance, $movement->balance);
            }
            $this->assertSame($balance, $product->quantity);
            $this->assertGreaterThanOrEqual(0, bccomp($product->quantity, '0', 3));
            if (str_starts_with($product->sku, 'DEMO-') && bccomp($product->quantity, '0', 3) > 0) {
                $this->assertSame(1, bccomp($product->average_cost, '0', 4));
            }
        }
        foreach (Sale::with('items', 'entries')->get() as $sale) {
            $itemsTotal = '0.00';
            $scheduled = '0.00';
            foreach ($sale->items as $item) {
                $itemsTotal = bcadd($itemsTotal, $item->total, 2);
            }
            foreach ($sale->entries as $entry) {
                $scheduled = bcadd($scheduled, $entry->amount, 2);
            }
            $this->assertSame($sale->total, $itemsTotal);
            $this->assertSame($sale->total, $scheduled);
        }
        foreach (FinancialEntry::with('payments')->get() as $entry) {
            $paid = (string) $entry->legacy_paid_amount;
            foreach ($entry->payments as $payment) {
                $paid = $payment->kind === 'refund' ? bcsub($paid, $payment->amount, 2) : bcadd($paid, $payment->amount, 2);
            }
            $this->assertSame($paid, $entry->paid_amount);
            $this->assertGreaterThanOrEqual(0, bccomp($entry->amount, $entry->paid_amount, 2));
        }
        $lastCounted = null;
        foreach (\App\Models\CashClosure::orderBy('date')->get() as $closure) {
            $date = $closure->date->toDateString();
            $query = \App\Models\Payment::where('paid_on', $date)->where('method', 'cash');
            $in = bcadd((string) (clone $query)->where('direction', 'in')->sum('amount'), '0', 2);
            $out = bcadd((string) (clone $query)->where('direction', 'out')->sum('amount'), '0', 2);
            $this->assertSame($in, $closure->cash_in);
            $this->assertSame($out, $closure->cash_out);
            $this->assertSame(bcsub(bcadd($closure->opening_balance, $in, 2), $out, 2), $closure->expected_balance);
            if ($lastCounted !== null) {
                $this->assertSame($lastCounted, $closure->opening_balance);
            }
            $lastCounted = $closure->counted_balance;
        }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a')->assertOk()->assertSee('sales-svg-title', false)
            ->assertViewHas('salesPeriodTotal', fn ($value) => bccomp((string) $value, '0', 2) > 0);
        $this->get('/app/a/cashflow')->assertOk()->assertSee('cashflow-svg-title', false)->assertSee('forecast-daily-table', false)
            ->assertViewHas('rows', fn ($rows) => collect($rows)->contains(fn ($row) => bccomp((string) $row['balance'], '0', 2) < 0));
        app(TenantConnection::class)->connect($this->a);
        $tables = ['products', 'product_groups', 'services', 'customers', 'suppliers', 'purchases', 'purchase_items', 'sales', 'sale_items', 'documents', 'stock_movements', 'financial_entries', 'payments', 'cash_closures', 'demo_runs', 'users'];
        $counts = fn () => array_map(fn ($table) => DB::connection('tenant')->table($table)->count(), $tables);
        $beforeReplay = $counts();
        $stockBeforeReplay = Product::orderBy('id')->get(['id', 'quantity', 'average_cost'])->toArray();
        $replayedSummary = $service->seed($this->user->id);
        // MySQL JSON objects have no contractual key order. Keep strict values/types.
        ksort($summary);
        ksort($replayedSummary);
        $this->assertSame($summary, $replayedSummary);
        $this->assertSame($beforeReplay, $counts());
        $this->assertSame($stockBeforeReplay, Product::orderBy('id')->get(['id', 'quantity', 'average_cost'])->toArray());
    }


    public function test_default_price_alert_preserves_price_when_average_cost_changes(): void
    {
        $group = ProductGroup::findOrFail($this->product->product_group_id);
        $this->assertSame('alert', $group->price_policy);
        $this->assertNull($this->product->fresh()->price_policy);
        $this->move('in', '10', '60');
        $review = $this->product->fresh()->priceReview;
        $this->assertSame('alert', $review->mode);
        $this->assertSame('100.00', $review->suggested_price);
        $this->assertFalse($review->needs_attention);

        $this->move('in', '10', '100');
        $product = $this->product->fresh();
        $this->assertSame('80.0000', $product->average_cost);
        $this->assertSame('100.00', $product->sale_price);
        $this->assertSame('133.33', $product->priceReview->suggested_price);
        $this->assertSame('80.0000', $product->priceReview->cost_snapshot);
        $this->assertSame('40.00', $product->priceReview->margin_snapshot);
        $this->assertSame('ready', $product->priceReview->state);
        $this->assertTrue($product->priceReview->needs_attention);
        $this->assertSame(0, \App\Models\PriceChange::count());
    }

    public function test_automatic_pricing_uses_weighted_cost_without_zeroing_or_partial_overflow(): void
    {
        $group = ProductGroup::findOrFail($this->product->product_group_id);
        $group->update(['price_policy' => 'automatic']);
        $this->move('in', '10', '60');
        $key = (string) Str::uuid();
        $this->move('in', '20', '90', $key);
        $this->assertSame('80.0000', $this->product->fresh()->average_cost);
        $this->assertSame('133.33', $this->product->fresh()->sale_price);
        $change = \App\Models\PriceChange::firstOrFail();
        $this->assertSame('100.00', $change->before_price);
        $this->assertSame('133.33', $change->after_price);
        $this->assertSame('automatic', $change->mode);
        $this->move('in', '20', '90', $key);
        $this->move('out', '2');
        $this->assertSame(1, \App\Models\PriceChange::count());
        $this->assertSame('133.33', $this->product->fresh()->sale_price);
        app(Inventory::class)->move($this->product->id, 'return', '2', '60', 'Devolução QA', $this->user->id, (string) Str::uuid());
        $this->assertSame('78.6667', $this->product->fresh()->average_cost);
        $this->assertSame('131.11', $this->product->fresh()->sale_price);
        $this->assertSame(2, \App\Models\PriceChange::count());

        $free = Product::create(['name' => 'Produto sem custo QA', 'sku' => 'NO-COST-QA', 'product_group_id' => $group->id, 'unit' => 'UN', 'sale_price' => '50.00']);
        app(Inventory::class)->move($free->id, 'in', '1', '0', 'Entrada gratuita QA', $this->user->id, (string) Str::uuid());
        $this->assertSame('50.00', $free->fresh()->sale_price);
        $this->assertSame('no_cost', $free->fresh()->priceReview->state);
        $this->assertNull($free->fresh()->priceReview->suggested_price);
        $this->assertFalse($free->fresh()->priceReview->needs_attention);

        $highMargin = ProductGroup::create(['name' => 'Margem limite QA', 'margin' => '99.99', 'price_policy' => 'automatic']);
        $overflow = Product::create(['name' => 'Limite automático QA', 'sku' => 'OVERFLOW-AUTO-QA', 'product_group_id' => $highMargin->id, 'unit' => 'UN', 'sale_price' => '10.00']);
        try {
            app(Inventory::class)->move($overflow->id, 'in', '1', '999999999.9999', 'Limite QA', $this->user->id, (string) Str::uuid());
            $this->fail('Preço automático fora do schema deve reverter a entrada toda.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sale_price', $e->errors());
            $this->assertSame('0.000', $overflow->fresh()->quantity);
            $this->assertSame('0.0000', $overflow->fresh()->average_cost);
            $this->assertSame('10.00', $overflow->fresh()->sale_price);
            $this->assertSame(0, StockMovement::where('product_id', $overflow->id)->count());
        }
        $alertOverflow = Product::create(['name' => 'Limite alerta QA', 'sku' => 'OVERFLOW-ALERT-QA', 'product_group_id' => $highMargin->id, 'unit' => 'UN', 'sale_price' => '10.00', 'price_policy' => 'alert']);
        app(Inventory::class)->move($alertOverflow->id, 'in', '1', '999999999.9999', 'Limite alerta QA', $this->user->id, (string) Str::uuid());
        $this->assertSame('1.000', $alertOverflow->fresh()->quantity);
        $this->assertSame('10.00', $alertOverflow->fresh()->sale_price);
        $this->assertSame('overflow', $alertOverflow->fresh()->priceReview->state);
        $this->assertNull($alertOverflow->fresh()->priceReview->suggested_price);
        $this->assertTrue($alertOverflow->fresh()->priceReview->needs_attention);
    }

    public function test_group_and_product_price_overrides_are_independent_and_keep_sale_history(): void
    {
        $pricing = app(\App\Services\PricingService::class);
        $group = ProductGroup::findOrFail($this->product->product_group_id);
        $group->update(['price_policy' => 'automatic']);
        $this->product->update(['margin' => '25.00']);
        $this->move('in', '10', '60');
        $this->assertSame('80.00', $this->product->fresh()->sale_price);
        $data = $this->saleData();
        $data['items'][0]['unit_price'] = '80.00';
        $sale = app(Checkout::class)->complete($data, $this->user->id);
        $group->update(['margin' => '50.00', 'price_policy' => 'alert']);
        $pricing->syncGroup($group->id, $this->user->id);
        $this->assertSame('25.00', $this->product->fresh()->priceReview->margin_snapshot);
        $this->assertSame('alert', $this->product->fresh()->priceReview->mode);
        $this->assertSame('80.00', $this->product->fresh()->sale_price);

        $this->product->update(['margin' => null, 'price_policy' => 'automatic']);
        $pricing->syncProduct($this->product->id, $this->user->id, 'qa:product-override');
        $this->assertSame('120.00', $this->product->fresh()->sale_price);
        $group->update(['margin' => '40.00', 'price_policy' => 'alert']);
        $pricing->syncGroup($group->id, $this->user->id);
        $this->assertSame('automatic', $this->product->fresh()->priceReview->mode);
        $this->assertSame('40.00', $this->product->fresh()->priceReview->margin_snapshot);
        $this->assertSame('100.00', $this->product->fresh()->sale_price);
        $this->product->update(['margin' => '20.00', 'price_policy' => 'alert']);
        $pricing->syncProduct($this->product->id, $this->user->id, 'qa:alert-override');
        $this->assertSame('100.00', $this->product->fresh()->sale_price);
        $this->assertSame('75.00', $this->product->fresh()->priceReview->suggested_price);
        $this->assertTrue($this->product->fresh()->priceReview->needs_attention);
        $this->assertSame('80.00', $sale->items()->firstOrFail()->unit_price);
        $this->assertSame('80.00', $sale->fresh()->total);
        $this->assertSame(3, \App\Models\PriceChange::count());
    }

    public function test_manual_price_apply_checks_fresh_suggestion_replay_and_operator_permissions(): void
    {
        $this->move('in', '10', '60');
        $this->move('in', '10', '100');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $url = '/app/a/prices/'.$this->product->id.'/apply';
        $this->post($url, ['request_key' => (string) Str::uuid(), 'expected_price' => '100.00'])->assertSessionHasErrors('expected_price');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('100.00', $this->product->fresh()->sale_price);
        $this->assertSame(0, \App\Models\PriceChange::count());
        $request = ['request_key' => (string) Str::uuid(), 'expected_price' => '133.33'];
        $this->post($url, $request)->assertRedirect();
        $this->post($url, $request)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('133.33', $this->product->fresh()->sale_price);
        $this->assertFalse($this->product->fresh()->priceReview->needs_attention);
        $this->assertSame(1, \App\Models\PriceChange::count());
        $this->post($url, array_merge($request, ['expected_price' => '100.00']))->assertSessionHasErrors('request_key');
        app(TenantConnection::class)->connect($this->a);
        $this->move('in', '1', '110');
        $this->assertTrue($this->product->fresh()->priceReview->needs_attention);
        $this->post($url, $request)->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('133.33', $this->product->fresh()->sale_price);
        $this->assertTrue($this->product->fresh()->priceReview->needs_attention);
        $this->assertSame(1, \App\Models\PriceChange::count());
        $this->user->update(['role' => 'operator']);
        $this->get('/app/a/prices')->assertForbidden();
        $this->post($url, [])->assertForbidden();
        $this->post('/app/a/prices/refresh', [])->assertForbidden();
    }


    public function test_nonstock_products_and_services_use_reference_cost_without_inventory_movements(): void
    {
        $groupId = $this->product->product_group_id;
        $goods = Product::create(['name' => 'Produto sem estoque QA', 'sku' => 'NONSTOCK-QA', 'product_group_id' => $groupId, 'type' => 'product', 'controls_stock' => false, 'reference_cost' => '12.3456', 'sale_price' => '30.00', 'unit' => 'UN']);
        $service = Product::create(['name' => 'Serviço unificado QA', 'sku' => 'UNIFIED-SERVICE-QA', 'product_group_id' => $groupId, 'type' => 'service', 'controls_stock' => false, 'reference_cost' => '20.0000', 'sale_price' => '50.00', 'unit' => 'UN']);
        $data = $this->saleData();
        $data['items'] = [
            ['kind' => 'product', 'id' => $goods->id, 'quantity' => '2', 'unit_price' => '30.00'],
            ['kind' => 'product', 'id' => $service->id, 'quantity' => '1', 'unit_price' => '50.00'],
        ];
        $sale = app(Checkout::class)->complete($data, $this->user->id);
        $this->assertSame('110.00', $sale->total);
        $this->assertSame('44.6912', $sale->cost_total);
        $this->assertSame(0, StockMovement::count());
        foreach ([$goods, $service] as $item) {
            $this->assertSame('0.000', $item->fresh()->quantity);
            try {
                app(Inventory::class)->move($item->id, 'in', '1', '10', 'Entrada indevida QA', $this->user->id, (string) Str::uuid());
                $this->fail('Item sem controle não deve aceitar movimentação de estoque.');
            } catch (ValidationException) {
                $this->assertSame(0, StockMovement::count());
            }
        }
        $supplier = \App\Models\Supplier::create(['name' => 'Fornecedor sem estoque QA']);
        $purchaseData = ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'purchased_on' => now()->toDateString(), 'payment_method' => 'invoice', 'paid' => false, 'installments' => 1, 'due_date' => now()->addMonth()->toDateString(), 'items' => [['product_id' => $goods->id, 'quantity' => '10', 'unit_cost' => '15.2500']]];
        $purchase = app(\App\Services\PurchaseService::class)->receive($purchaseData, $this->user->id);
        $this->assertSame('152.50', $purchase->total);
        $this->assertSame('15.2500', $goods->fresh()->reference_cost);
        $this->assertSame('0.0000', $goods->fresh()->average_cost);
        $this->assertSame('0.000', $goods->fresh()->quantity);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('out', $purchase->entries()->firstOrFail()->direction);
        $this->assertSame('152.50', $purchase->entries()->firstOrFail()->amount);
        $this->assertSame('12.3456', $sale->items()->where('product_id', $goods->id)->firstOrFail()->unit_cost);
        $invalid = $purchaseData;
        $invalid['request_key'] = (string) Str::uuid();
        $invalid['items'][] = ['product_id' => $service->id, 'quantity' => '1', 'unit_cost' => '20'];
        try {
            app(\App\Services\PurchaseService::class)->receive($invalid, $this->user->id);
            $this->fail('Serviço não deve entrar por recebimento de produtos.');
        } catch (ValidationException) {
            $this->assertSame(1, \App\Models\Purchase::count());
            $this->assertSame('15.2500', $goods->fresh()->reference_cost);
        }
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        $this->assertSame(0, StockMovement::count());
        $this->assertSame('0.000', $goods->fresh()->quantity);
        $this->assertSame('0.000', $service->fresh()->quantity);
    }

    public function test_stock_control_changes_obey_current_balance_and_original_sale_participation(): void
    {
        $this->move('in', '10', '60');
        $sale = app(Checkout::class)->complete($this->saleData('2'), $this->user->id);
        foreach ([['controls_stock' => false], ['controls_stock' => false, 'quantity' => '0'], ['type' => 'service', 'controls_stock' => false]] as $change) {
            try {
                $this->product->fresh()->update($change);
                $this->fail('Saldo atual impede desligar o estoque ou converter em serviço.');
            } catch (ValidationException) {
                $this->assertTrue($this->product->fresh()->controlsStock());
                $this->assertSame('8.000', $this->product->fresh()->quantity);
            }
        }
        $this->move('out', '8');
        $this->product->fresh()->update(['controls_stock' => false, 'reference_cost' => '60.0000']);
        $this->assertSame('60.0000', $this->product->fresh()->average_cost);
        try {
            app(Checkout::class)->cancel($sale->id, $this->user->id, true);
            $this->fail('Venda que baixou estoque exige reativá-lo antes do estorno.');
        } catch (ValidationException) {
            $this->assertSame('completed', $sale->fresh()->status);
            $this->assertSame('0.000', $this->product->fresh()->quantity);
            $this->assertSame(1, \App\Models\Payment::count());
        }
        $this->product->fresh()->update(['controls_stock' => true]);
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        $this->assertSame('2.000', $this->product->fresh()->quantity);

        $nonstock = Product::create(['name' => 'Controle ativado depois QA', 'sku' => 'LATER-STOCK-QA', 'product_group_id' => $this->product->product_group_id, 'type' => 'product', 'controls_stock' => false, 'reference_cost' => '10.0000', 'sale_price' => '20.00', 'unit' => 'UN']);
        $data = $this->saleData();
        $data['items'] = [['kind' => 'product', 'id' => $nonstock->id, 'quantity' => '3', 'unit_price' => '20.00']];
        $nonstockSale = app(Checkout::class)->complete($data, $this->user->id);
        $nonstock->fresh()->update(['controls_stock' => true]);
        app(Checkout::class)->cancel($nonstockSale->id, $this->user->id, true);
        $this->assertSame('0.000', $nonstock->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('product_id', $nonstock->id)->count());
    }

    public function test_company_logo_is_sanitized_private_and_scoped_to_its_tenant(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->get('/app/a/business/logo')->assertRedirect('/app/a/login');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $svg = \Illuminate\Http\UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->post('/app/a/business', ['legal_name' => 'Cliente A QA', 'logo' => $svg])->assertSessionHasErrors('logo');
        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('local')->allFiles());
        $image = \Illuminate\Http\UploadedFile::fake()->image('client-logo.png', 96, 96);
        file_put_contents($image->getRealPath(), '<?php QA-PHP-MARKER', FILE_APPEND);
        $this->post('/app/a/business', ['legal_name' => 'Cliente A QA', 'logo' => $image, 'logo_path' => 'tenants/999999/branding/unsafe.png'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $profile = \App\Models\BusinessProfile::findOrFail(1);
        $path = $profile->logo_path;
        $this->assertStringStartsWith('tenants/'.$this->a->id.'/branding/', $path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertExists($path);
        $stored = \Illuminate\Support\Facades\Storage::disk('local')->get($path);
        $this->assertStringNotContainsString('QA-PHP-MARKER', $stored);
        $this->assertSame(IMAGETYPE_PNG, getimagesizefromstring($stored)[2]);
        $response = $this->get('/app/a/business/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringStartsWith('image/png', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->get('/app/a/reports')->assertOk()->assertSee('/app/a/business/logo', false)->assertSee('Cliente A QA');
        $brandedCsv = $this->get('/app/a/reports/export/stock')->assertOk()->streamedContent();
        $this->assertStringContainsString('Cliente A QA', $brandedCsv);
        $this->get('/app/b/business/logo')->assertRedirect('/app/b/login');

        app(TenantConnection::class)->connect($this->b);
        $otherUser = User::forceCreate(['id' => $this->user->id, 'name' => 'Administrador B', 'email' => 'logo-b@example.test', 'password' => 'other-logo-admin-password', 'role' => 'admin']);
        \App\Models\BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Cliente B QA', 'logo_path' => $path]);
        $otherResponse = $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $otherUser->id])->get('/app/b/business/logo');
        $this->assertContains($otherResponse->getStatusCode(), [200, 403, 404]);
        if ($otherResponse->getStatusCode() === 200) {
            $this->assertStringStartsWith('image/svg+xml', $otherResponse->headers->get('Content-Type'));
        }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->post('/app/a/business', ['legal_name' => 'Cliente A QA', 'remove_logo' => '1'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertNull(\App\Models\BusinessProfile::findOrFail(1)->logo_path);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing($path);
    }


    public function test_store_labels_normalize_new_documents_only_for_proven_cohort_and_exact_text(): void
    {
        app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
        $cohort = Product::where('sku', 'DEMO-MOUSE')->firstOrFail();
        $oldDescription = $cohort->name;
        $this->product->fresh()->update(['name' => $oldDescription, 'controls_stock' => false, 'reference_cost' => '10.0000']);
        $items = [
            ['kind' => 'product', 'id' => $cohort->id, 'quantity' => '1', 'unit_price' => '49.00'],
            ['kind' => 'product', 'id' => $cohort->id, 'quantity' => '1', 'unit_price' => '49.00'],
            ['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '100.00'],
        ];
        $data = $this->saleData();
        $data['items'] = $items;
        $data['paid'] = false;
        $data['due_date'] = now()->addMonth()->toDateString();
        $sale = app(Checkout::class)->complete($data, $this->user->id);
        $cohortLines = $sale->items()->where('product_id', $cohort->id)->orderBy('id')->get();
        $cohortLines[1]->update(['description' => 'Descrição personalizada preservada']);
        $outsideLine = $sale->items()->where('product_id', $this->product->id)->firstOrFail();
        $document = app(Documents::class)->create(['type' => 'quote', 'request_key' => (string) Str::uuid(), 'items' => $items, 'notes' => 'Nota personalizada preservada'], $this->user->id);
        $documentItems = $document->items;
        $documentItems[0]['custom_reference'] = 'Manter metadado do cliente';
        $documentItems[1]['description'] = 'Descrição personalizada preservada';
        $document->update(['items' => $documentItems]);
        $documentBefore = $document->fresh()->items;
        $factsBefore = $this->storeFinancialFacts();

        $result = app(\App\Services\ComputerStoreLabels::class)->normalize();

        $this->assertTrue($result['eligible']);
        $cleanName = $cohort->fresh()->name;
        $this->assertStringNotContainsString('demo', strtolower($cleanName));
        $this->assertStringNotContainsString('demo', strtolower($cohort->fresh()->sku));
        $this->assertSame($cleanName, $cohortLines[0]->fresh()->description);
        $this->assertSame('Descrição personalizada preservada', $cohortLines[1]->fresh()->description);
        $this->assertSame($oldDescription, $outsideLine->fresh()->description);
        $this->assertSame($oldDescription, $this->product->fresh()->name);
        $documentExpected = $documentBefore;
        $documentExpected[0]['description'] = $cleanName;
        $this->assertSame($this->canonicalJson($documentExpected), $this->canonicalJson($document->fresh()->items));
        $this->assertSame('Nota personalizada preservada', $document->fresh()->notes);
        $this->assertSame($factsBefore, $this->storeFinancialFacts());
    }

    public function test_store_labels_replay_preserves_cash_financial_values_and_unrelated_user_data(): void
    {
        app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
        $this->user->update(['name' => 'Usuário Demo da Empresa']);
        $this->product->fresh()->update(['name' => 'Notebook Demonstração Design — registro externo']);
        $userBefore = $this->user->fresh()->getAttributes();
        $outsideBefore = $this->product->fresh()->getAttributes();
        $factsBefore = $this->storeFinancialFacts();
        $normalizer = app(\App\Services\ComputerStoreLabels::class);
        $first = $normalizer->normalize();
        $this->assertGreaterThan(0, $first['updated']);
        $this->assertSame($factsBefore, $this->storeFinancialFacts());
        $labelState = fn () => [
            'products' => Product::orderBy('id')->get(['id', 'name', 'sku'])->toArray(),
            'groups' => ProductGroup::orderBy('id')->get(['id', 'name'])->toArray(),
            'customers' => \App\Models\Customer::orderBy('id')->get(['id', 'name', 'email', 'address'])->toArray(),
            'suppliers' => \App\Models\Supplier::orderBy('id')->get(['id', 'name', 'email', 'address'])->toArray(),
            'sale_items' => \App\Models\SaleItem::orderBy('id')->get(['id', 'description'])->toArray(),
            'documents' => \App\Models\Document::orderBy('id')->get(['id', 'items', 'notes'])->toArray(),
        ];
        $afterFirst = $labelState();
        $second = $normalizer->normalize();
        $this->assertSame(0, $second['updated']);
        $this->assertSame($afterFirst, $labelState());
        $this->assertSame($factsBefore, $this->storeFinancialFacts());
        $this->assertSame($userBefore, $this->user->fresh()->getAttributes());
        $this->assertSame($outsideBefore, $this->product->fresh()->getAttributes());
    }

    private function storeFinancialFacts(): array
    {
        $columns = [
            'sales' => ['id', 'request_key', 'customer_id', 'user_id', 'status', 'payment_method', 'paid', 'due_date', 'total', 'cost_total', 'created_at'],
            'sale_items' => ['id', 'sale_id', 'product_id', 'service_id', 'quantity', 'unit_price', 'unit_cost', 'total'],
            'purchases' => ['id', 'request_key', 'supplier_id', 'user_id', 'purchased_on', 'total', 'payment_method', 'paid'],
            'purchase_items' => ['id', 'purchase_id', 'product_id', 'quantity', 'unit_cost', 'total'],
            'financial_entries' => ['id', 'request_key', 'user_id', 'sale_id', 'purchase_id', 'direction', 'amount', 'due_date', 'paid_amount', 'legacy_paid_amount', 'paid_at', 'cancelled_at'],
            'payments' => ['*'],
            'cash_closures' => ['*'],
            'stock_movements' => ['*'],
        ];
        $snapshot = [];
        foreach ($columns as $table => $fields) {
            $snapshot[$table] = DB::connection('tenant')->table($table)->orderBy('id')->get($fields)->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }

    private function canonicalJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $entry) {
            $value[$key] = $this->canonicalJson($entry);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        return $value;
    }


    public function test_annual_history_preserves_existing_cohort_and_bridges_stock_cash_and_year_coverage(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:00:00'));
        try {
            app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
            $current = Product::where('sku', 'DEMO-MOUSE')->firstOrFail();
            $extra = $this->saleData();
            $extra['items'] = [['kind' => 'product', 'id' => $current->id, 'quantity' => '1', 'unit_price' => '49.00']];
            $extra['paid'] = false;
            $extra['due_date'] = '2026-12-10';
            app(Checkout::class)->complete($extra, $this->user->id);
            $facts = $this->storeFinancialFacts();
            $oldIds = Product::orderBy('id')->pluck('id')->all();
            $stockFields = ['id', 'type', 'controls_stock', 'quantity', 'average_cost', 'reference_cost', 'sale_price', 'active'];
            $stockBefore = Product::whereIn('id', $oldIds)->orderBy('id')->get($stockFields)->toArray();
            $firstClosure = \App\Models\CashClosure::orderBy('date')->firstOrFail();
            $firstDate = $firstClosure->date->toDateString();
            $opening = $firstClosure->opening_balance;
            $service = app(\App\Services\AnnualComputerStore::class);
            $plan = $service->plan(2026);
            $this->assertSame('2026-01-01', $plan['import_start']);
            $this->assertLessThan($firstDate, $plan['import_end']);
            $this->assertSame(0, $plan['overlapping_payments']);
            $summary = $service->seed($this->user->id, 2026);
            $this->assertSame($facts, $this->existingStoreFinancialFacts($facts));
            $this->assertSame($stockBefore, Product::whereIn('id', $oldIds)->orderBy('id')->get($stockFields)->toArray());
            $archive = Product::where('sku', 'like', 'VXH-2026-%')->get();
            $this->assertNotEmpty($archive);
            foreach ($archive as $item) {
                $this->assertFalse($item->active);
                $this->assertSame('0.000', $item->quantity);
                $this->assertNotContains($item->id, $oldIds);
            }
            $months = Sale::where('status', 'completed')->whereYear('created_at', 2026)->selectRaw('MONTH(created_at) AS sale_month')->distinct()->orderBy('sale_month')->pluck('sale_month')->map(fn ($month) => (int) $month)->all();
            $this->assertSame(range(1, 11), $months);
            $this->assertSame(0, Sale::where('status', 'completed')->whereDate('created_at', '>', '2026-11-12')->count());
            $this->assertSame(0, \App\Models\Payment::where('paid_on', '>', '2026-11-12')->count());
            $this->assertSame(0, \App\Models\CashClosure::where('date', '>', '2026-11-12')->count());
            $this->assertGreaterThan(0, FinancialEntry::whereYear('due_date', 2026)->whereMonth('due_date', 12)->whereNull('cancelled_at')->whereColumn('paid_amount', '<', 'amount')->count());
            $oldPaymentIds = array_column($facts['payments'], 'id');
            $newPayments = \App\Models\Payment::whereNotIn('id', $oldPaymentIds);
            $this->assertGreaterThan(0, (clone $newPayments)->count());
            $this->assertSame(0, (clone $newPayments)->where(fn ($query) => $query->where('paid_on', '<', $plan['import_start'])->orWhere('paid_on', '>', $plan['import_end']))->count());
            $bridge = \App\Models\CashClosure::where('date', '<', $firstDate)->orderByDesc('date')->firstOrFail();
            $this->assertSame($opening, $bridge->counted_balance);
            foreach ($archive->where('type', 'product') as $item) {
                $movements = StockMovement::where('product_id', $item->id)->get();
                $this->assertGreaterThan(0, $movements->count());
                $incoming = $movements->whereIn('type', ['in', 'return'])->reduce(fn ($sum, $move) => bcadd($sum, $move->quantity, 3), '0.000');
                $outgoing = $movements->whereIn('type', ['out', 'sale'])->reduce(fn ($sum, $move) => bcadd($sum, $move->quantity, 3), '0.000');
                $this->assertSame($incoming, $outgoing);
            }
            $differences = [];
            $previousCounted = null;
            foreach (\App\Models\CashClosure::orderBy('date')->get() as $closure) {
                $date = $closure->date->toDateString();
                $payments = \App\Models\Payment::where('paid_on', $date)->get();
                $cash = $payments->where('method', 'cash');
                $incoming = $cash->where('direction', 'in')->reduce(fn ($sum, $payment) => bcadd($sum, $payment->amount, 2), '0.00');
                $outgoing = $cash->where('direction', 'out')->reduce(fn ($sum, $payment) => bcadd($sum, $payment->amount, 2), '0.00');
                if ($closure->cash_in !== $incoming || $closure->cash_out !== $outgoing ||
                    $closure->expected_balance !== bcsub(bcadd($closure->opening_balance, $incoming, 2), $outgoing, 2) ||
                    ($closure->snapshot['payment_count'] ?? null) !== $payments->count() ||
                    ($previousCounted !== null && $closure->opening_balance !== $previousCounted)) {
                    $differences[] = 'cash:'.$date;
                }
                $previousCounted = $closure->counted_balance;
            }
            foreach (Sale::with('items', 'entries')->get() as $sale) {
                $items = $sale->items->reduce(fn ($sum, $item) => bcadd($sum, $item->total, 2), '0.00');
                $scheduled = $sale->entries->reduce(fn ($sum, $entry) => bcadd($sum, $entry->amount, 2), '0.00');
                if ($items !== $sale->total || $scheduled !== $sale->total) {
                    $differences[] = 'sale:'.$sale->id;
                }
            }
            foreach (FinancialEntry::with('payments')->get() as $entry) {
                $paid = $entry->payments->reduce(fn ($sum, $payment) => $payment->kind === 'refund'
                    ? bcsub($sum, $payment->amount, 2) : bcadd($sum, $payment->amount, 2), (string) $entry->legacy_paid_amount);
                if ($paid !== $entry->paid_amount || bccomp($paid, $entry->amount, 2) > 0) {
                    $differences[] = 'entry:'.$entry->id;
                }
            }
            $this->assertSame([], $differences, 'Daily cash continuity and sales/payment reconciliation');
            $tables = ['products', 'sales', 'sale_items', 'purchases', 'purchase_items', 'financial_entries', 'payments', 'stock_movements', 'cash_closures', 'demo_runs'];
            $counts = fn () => array_map(fn ($table) => DB::connection('tenant')->table($table)->count(), $tables);
            $beforeReplay = $counts();
            $this->assertSame($this->canonicalJson($summary), $this->canonicalJson($service->seed($this->user->id, 2026)));
            $this->assertSame($beforeReplay, $counts());
            $this->assertSame($facts, $this->existingStoreFinancialFacts($facts));
        } finally {
            $this->travelBack();
        }
    }

    public function test_annual_history_complete_year_has_balanced_months_and_preserves_existing_data(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:00:00'));
        try {
            app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
            $before = $this->storeFinancialFacts();
            $service = app(\App\Services\AnnualComputerStore::class);
            $plan = $service->plan(2025);
            $this->assertSame('2025-12-31', $plan['import_end']);
            $this->assertTrue($plan['balanced_monthly_volume']);
            $this->assertSame(528, $plan['estimated_sales']);
            $summary = $service->seed($this->user->id, 2025);
            $this->assertSame($before, $this->existingStoreFinancialFacts($before));
            $months = Sale::whereYear('created_at', 2025)->where('status', 'completed')
                ->selectRaw('MONTH(created_at) as month, COUNT(*) as quantity, SUM(total) as revenue')
                ->groupByRaw('MONTH(created_at)')->orderBy('month')->get();
            $this->assertSame(range(1, 12), $months->pluck('month')->map(fn ($value) => (int) $value)->all());
            $this->assertSame(array_fill(0, 12, 44), $months->pluck('quantity')->map(fn ($value) => (int) $value)->all());
            $revenues = $months->pluck('revenue')->map(fn ($value) => (float) $value)->all();
            $this->assertLessThan(1.15, max($revenues) / min($revenues));
            $this->assertSame(365, \App\Models\CashClosure::whereYear('date', 2025)->count());
            $bridge = \App\Models\CashClosure::whereDate('date', '2025-12-31')->firstOrFail();
            $originalOpening = $before['cash_closures'][0]['opening_balance'];
            $this->assertSame($originalOpening, $bridge->counted_balance);
            $this->assertSame(0, \App\Models\Payment::where('paid_on', '>', '2026-11-12')->count());
            $counts = [Sale::count(), \App\Models\Payment::count(), \App\Models\CashClosure::count()];
            $this->assertSame($this->canonicalJson($summary), $this->canonicalJson($service->seed($this->user->id, 2025)));
            $this->assertSame($counts, [Sale::count(), \App\Models\Payment::count(), \App\Models\CashClosure::count()]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_annual_history_requires_prepared_base_and_refuses_overlapping_financial_records(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-12 12:00:00'));
        try {
            $service = app(\App\Services\AnnualComputerStore::class);
            try {
                $service->seed($this->user->id, 2026);
                $this->fail('Importação anual exige base de origem comprovada.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('history', $e->errors());
                $this->assertSame(1, Product::count());
                $this->assertSame(0, Sale::count());
            }
            app(\App\Services\DemoComputerStore::class)->seed($this->user->id);
            $entry = app(\App\Services\FinancialService::class)->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'in', 'description' => 'Recebimento histórico já existente', 'category' => 'Importação anterior', 'amount' => '10.00', 'due_date' => '2026-04-01'], $this->user->id)->first();
            try {
                app(\App\Services\FinancialService::class)->pay($entry->id, '10.00', '2026-04-01', 'pix', $this->user->id, (string) Str::uuid());
                $this->fail('A API operacional não pode abrir uma exceção para retroagir a baixa.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('paid_on', $e->errors());
            }
            // Fixture representing an already-restored legacy record, not a bypass in operational APIs.
            \App\Models\Payment::create(['request_key' => (string) Str::uuid(), 'entry_id' => $entry->id, 'user_id' => $this->user->id, 'amount' => '10.00', 'paid_on' => '2026-04-01', 'method' => 'pix', 'direction' => 'in', 'kind' => 'payment']);
            $entry->update(['paid_amount' => '10.00', 'paid_at' => '2026-04-01 12:00:00']);
            $facts = $this->storeFinancialFacts();
            $products = Product::orderBy('id')->get(['id', 'sku', 'quantity', 'average_cost'])->toArray();
            $this->assertSame(1, $service->plan(2026)['overlapping_payments']);
            try {
                $service->seed($this->user->id, 2026);
                $this->fail('Importação deve recusar sobreposição financeira sem alterar dados.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('history', $e->errors());
                $this->assertSame($facts, $this->storeFinancialFacts());
                $this->assertSame($products, Product::orderBy('id')->get(['id', 'sku', 'quantity', 'average_cost'])->toArray());
                $this->assertSame(0, \App\Models\DemoRun::where('code', 'annual-computer-history-v1-2026')->count());
            }
        } finally {
            $this->travelBack();
        }
    }

    private function existingStoreFinancialFacts(array $facts): array
    {
        $preserved = [];
        foreach ($facts as $table => $rows) {
            $preserved[$table] = $rows === [] ? [] : DB::connection('tenant')->table($table)->whereIn('id', array_column($rows, 'id'))->orderBy('id')->get(array_keys($rows[0]))->map(fn ($row) => (array) $row)->all();
        }
        return $preserved;
    }

    /** Fork separate connections; align initial reads, or start distinct operations together. */
    private function race(?string $table, \Closure $operation, ?\Closure $otherOperation = null): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concorrência exige pcntl; demais casos não são substitutos para esse teste.');
        }
        $directory = sys_get_temp_dir().'/vendra-race-'.Str::uuid();
        mkdir($directory, 0700);
        $children = [];
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Não foi possível criar worker de teste.');
                }
                if ($pid === 0) {
                    DB::purge('central');
                    DB::purge('tenant');
                    $waitForPeer = function () use ($directory, $worker) {
                        file_put_contents($directory.'/ready-'.$worker, 'ready');
                        $deadline = microtime(true) + 15;
                        while (count(glob($directory.'/ready-*')) < 2) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Timeout na barreira concorrente.');
                            }
                            usleep(10000);
                        }
                    };
                    try {
                        if ($table === null) {
                            $waitForPeer();
                        } else {
                            $reachedBarrier = false;
                            DB::listen(function ($query) use ($table, $waitForPeer, &$reachedBarrier) {
                                if ($reachedBarrier || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, '`'.$table.'`') || ! str_contains($query->sql, 'request_key')) {
                                    return;
                                }
                                $reachedBarrier = true;
                                $waitForPeer();
                            });
                        }
                        $workerOperation = $worker === 1 && $otherOperation !== null ? $otherOperation : $operation;
                        $result = ['id' => $workerOperation()];
                    } catch (\Throwable $e) {
                        // Store type only: a failed SQL exception may contain private data.
                        $result = ['error' => $e::class];
                    }
                    file_put_contents($directory.'/result-'.$worker, json_encode($result));
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Worker não concluiu normalmente.');
            }
            DB::purge('central');
            DB::purge('tenant');
            $ids = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $path = $directory.'/result-'.$worker;
                $this->assertFileExists($path);
                $result = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result, 'Worker falhou: '.($result['error'] ?? ''));
                $ids[] = $result['id'];
            }
            return $ids;
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
    public function test_company_setup_is_admin_only_and_each_step_renders(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        foreach (range(1,5) as $step) $this->get('/app/a/setup?step='.$step)->assertOk()->assertSee('Operação e implantação');
        $this->get('/app/a/setup?step[]=1')->assertSessionHasErrors('step');
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['role'=>'operator']);
        $this->get('/app/a/setup')->assertForbidden();
        $this->post('/app/a/setup',$this->operationPayload())->assertForbidden();
        $this->post('/app/a/setup/complete',['version'=>0,'reviewed'=>1])->assertForbidden();
    }

    public function test_guided_setup_saves_real_company_team_and_configuration_without_catalog_data(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get('/app/a/setup?step=1')->assertOk()->assertSee('Salvar empresa e continuar')->assertDontSee('Preencher dados e enviar logo');
        $this->post('/app/a/setup/company',['legal_name'=>'Empresa Implantada','document'=>'07.396.600/0001-60','state'=>'MG','city'=>'Lavras','municipality_code'=>'3138203','tax_regime'=>'simples'])->assertRedirect('/app/a/setup?step=2')->assertSessionHasNoErrors();
        $this->get('/app/a/business')->assertOk()->assertSee('Empresa Implantada');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2,app(\App\Services\CompanyOnboarding::class)->step());
        $version=\App\Models\CompanyOperation::find(1)->version;
        $this->post('/app/a/setup',$this->operationPayload(['version'=>$version,'nfce'=>false,'nfe'=>false,'nfse'=>false]))->assertRedirect('/app/a/setup?step=3')->assertSessionHasNoErrors();
        $this->post('/app/a/setup/fiscal/continue')->assertRedirect('/app/a/setup?step=4');
        $this->from('/app/a/setup?step=4')->post('/app/a/users',['name'=>'Caixa','email'=>'cashier@example.test','role'=>'operator','password'=>'initial-cashier-1234','password_confirmation'=>'initial-cashier-1234','access_profile'=>'operator'])->assertRedirect('/app/a/setup?step=4')->assertSessionHasNoErrors();
        $this->post('/app/a/setup/team',['name'=>'Responsável do caixa','user_id'=>$this->user->id,'active'=>1])->assertRedirect('/app/a/setup?step=5')->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1,\App\Models\Seller::where('name','Responsável do caixa')->count());$version=\App\Models\CompanyOperation::find(1)->version;
        $this->post('/app/a/setup/complete',['version'=>$version,'reviewed'=>1])->assertRedirect('/app/a')->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertNotNull(\App\Models\CompanyOperation::find(1)->completed_at);
        $this->assertNotEmpty(\App\Models\CompanyOperation::find(1)->configuration['_onboarding_completed_at']);
        $this->assertSame(1,Product::count());$this->assertSame(0,Sale::count());$this->assertSame(0,StockMovement::count());
        app(TenantConnection::class)->connect($this->b);$this->assertNull(\App\Models\BusinessProfile::find(1));$this->assertSame(0,\App\Models\Seller::count());
    }
    public function test_setup_rejects_invalid_company_missing_fiscal_and_team_without_completing(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->post('/app/a/setup/company',['legal_name'=>'Sem CNPJ','document'=>'123'])->assertSessionHasErrors('document');
        $this->post('/app/a/setup/fiscal/continue')->assertSessionHasErrors('configuration');
        $this->post('/app/a/setup/team',['name'=>'','active'=>1])->assertSessionHasErrors('name');
        $this->post('/app/a/setup/complete',['version'=>0,'reviewed'=>1])->assertSessionHasErrors('team');
        app(TenantConnection::class)->connect($this->a);$this->assertNull(\App\Models\CompanyOperation::find(1)?->completed_at);
    }
    public function test_nfse_partial_draft_keeps_unknown_numbering_empty_and_never_enables_emission(): void
    {
        \App\Models\BusinessProfile::forceCreate(['id'=>1,'legal_name'=>'Empresa','document'=>'07.396.600/0001-60','municipality_code'=>'3138203','tax_regime'=>'simples']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->from('/app/a/setup?step=3')->post('/app/a/nfse/configuration/draft',['municipality_code'=>'3138203','timezone'=>'America/Sao_Paulo','op_simp_nac'=>'3','reg_ap_trib_sn'=>'1','service_national_code'=>'010701','nbs'=>'115011000'])->assertSessionHasNoErrors()->assertRedirect('/app/a/setup?step=3');
        app(TenantConnection::class)->connect($this->a);
        $draft=app(\App\Fiscal\Nfse\Configuration::class)->draftValues();$this->assertSame('010701',$draft['service_national_code']);$this->assertArrayNotHasKey('series',$draft);
        $this->assertSame(0,\App\Models\NfseConfiguration::count());$this->assertSame(0,DB::connection('tenant')->table('nfse_sequences')->count());
        $this->post('/app/a/nfse/configuration/draft',['series'=>70000])->assertSessionHasErrors('series');
        $this->post('/app/a/nfse/configuration/draft',['certificate_password'=>'must-not-flash'])->assertSessionHasErrors('certificate_password')->assertSessionMissing('_old_input.certificate_password');
        app(TenantConnection::class)->connect($this->b);$this->assertSame([],app(\App\Fiscal\Nfse\Configuration::class)->draftValues());
    }
    public function test_global_support_is_identified_audited_and_cannot_be_used_without_central_session(): void
    {
        $admin=\App\Platform\PlatformAdmin::create(['name'=>'Suporte central','email'=>'global@example.test','password'=>'global-password-1234','active'=>true,'auth_version'=>1]);
        $this->withSession(['platform_admin_id'=>$admin->id,'platform_auth_version'=>1]);
        $this->post('/vexo-admin/companies/'.$this->a->id.'/support',['reason'=>'Implantação da empresa'])->assertRedirect('/app/a');
        $this->get('/app/a')->assertOk()->assertSee('Suporte Vexo · DBA');
        $this->from('/app/a/business')->post('/app/a/business',['legal_name'=>'Alteração global'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1,\App\Platform\PlatformAudit::where('action','support.enter')->count());
        $this->assertSame(1,\App\Platform\PlatformAudit::where('action','support.request')->count());
        app(TenantConnection::class)->connect($this->a);$support=User::where('email','like','%@support.vexo.invalid')->firstOrFail();
        $this->get('/app/a/users')->assertOk()->assertDontSee($support->email);
        $this->post('/app/a/users/'.$support->id,['name'=>'Bypass','role'=>'admin','active'=>1,'email'=>'bypass@example.test'])->assertForbidden();
        $this->post('/vexo-admin/support/leave')->assertRedirect('/vexo-admin')->assertSessionMissing('platform_support')->assertSessionMissing('tenant_id');
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->get('/app/a')->assertOk()->assertDontSee('Suporte Vexo · DBA');
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$support->id,'auth_version'=>$support->auth_version])->get('/app/a')->assertRedirect('/vexo-admin/login');
        $this->withSession(['platform_admin_id'=>$admin->id,'platform_auth_version'=>1])->post('/vexo-admin/companies/'.$this->a->id.'/support',['reason'=>'Teste de revogação'])->assertRedirect('/app/a');
        $admin->update(['active'=>false,'auth_version'=>2]);
        $this->get('/app/a')->assertRedirect('/vexo-admin/login')->assertSessionMissing('tenant_id');
    }
    public function test_global_support_cannot_jump_store_context_and_preserves_configuration_metadata(): void
    {
        $admin=\App\Platform\PlatformAdmin::create(['name'=>'Suporte','email'=>'global@example.test','password'=>'global-password-1234','active'=>true,'auth_version'=>1]);
        $this->withSession(['platform_admin_id'=>$admin->id,'platform_auth_version'=>1]);
        $this->post('/vexo-admin/companies/'.$this->a->id.'/support',['reason'=>'Configurar loja A'])->assertRedirect('/app/a');
        app(TenantConnection::class)->connect($this->a);
        $version=\App\Models\CompanyOperation::find(1)->version;
        app(\App\Services\CompanyOperations::class)->save($this->operationPayload(['version'=>$version,'commissions'=>false]),$this->user->id);
        $this->assertNotEmpty(\App\Models\CompanyOperation::find(1)->configuration['_platform_support']);
        $this->get('/app/b/desk')->assertRedirect('/app/b/login')->assertSessionMissing('platform_admin_id');
        app(TenantConnection::class)->connect($this->b);$this->assertSame(0,User::where('email','like','%@support.vexo.invalid')->count());
    }

    private function commissionSale(string $total='100',bool $paid=true,?int $seller=null,array $extra=[]): Sale
    {
        $this->product->update(['controls_stock'=>false]);$this->product->group->update(['commission_percent'=>'10']);
        return app(Checkout::class)->complete(array_replace(['request_key'=>(string)Str::uuid(),'salesperson_id'=>$seller,'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>bcdiv($total,'100',3),'unit_price'=>'100']],'payment_method'=>'pix','paid'=>$paid,'due_date'=>now()->addDay()->toDateString()],$extra),$this->user->id);
    }
    private function commissionInput(Sale $sale): array
    {
        return ['from'=>now()->startOfMonth()->toDateString(),'to'=>now()->toDateString(),'salesperson_id'=>$sale->salesperson_id];
    }
    private function commissionCloseInput(array $preview): array
    {
        return array_intersect_key($preview,array_flip(['from','to','salesperson_id','preview_hash']))+['request_key'=>(string)Str::uuid(),'due_date'=>now()->toDateString(),'reviewed'=>1];
    }
    public function test_commission_closure_creates_one_payable_and_payments_reconcile_without_double_close(): void
    {
        $sale=$this->commissionSale();$service=app(\App\Services\CommissionSettlements::class);$preview=$service->preview($this->commissionInput($sale));
        $this->assertSame('10.00',$preview['total']);$input=$this->commissionCloseInput($preview);$closure=$service->close($input,$this->user->id);
        $this->assertSame($closure->id,$service->close($input,$this->user->id)->id);
        $entry=FinancialEntry::findOrFail($closure->after['entry_id']);$this->assertSame('out',$entry->direction);$this->assertSame('10.00',$entry->amount);$this->assertSame('0.00',$entry->paid_amount);
        $this->assertSame('0.00',$service->preview($this->commissionInput($sale))['total']);
        try{$service->close($this->commissionCloseInput($preview),$this->user->id);$this->fail('Duplicate recognition accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('preview_hash',$e->errors());}
        $pay=['request_key'=>(string)Str::uuid(),'amount'=>'4.00','paid_on'=>now()->toDateString(),'payment_method'=>'pix'];$payment=$service->pay($closure->id,$pay,$this->user->id);$this->assertSame($payment->id,$service->pay($closure->id,$pay,$this->user->id)->id);
        $this->assertSame('6.00',$entry->fresh()->balance);$service->pay($closure->id,array_replace($pay,['request_key'=>(string)Str::uuid(),'amount'=>'6.00']),$this->user->id);
        $this->assertSame('10.00',$entry->fresh()->paid_amount);$this->assertNotNull($entry->fresh()->paid_at);
        $this->assertSame(['closed'=>'10.00','paid'=>'10.00','balance'=>'0.00'],$service->balances($sale->salesperson_id));
    }
    public function test_commissions_partial_receipts_enter_later_period_and_cancellation_adjusts_next_close(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 12:00:00'));
        try{
            $sale=$this->commissionSale('100',false);$entry=$sale->entries()->firstOrFail();$service=app(\App\Services\CommissionSettlements::class);
            app(\App\Services\FinancialService::class)->pay($entry->id,'50','2026-10-10','pix',$this->user->id,(string)Str::uuid());
            $first=$service->close($this->commissionCloseInput($service->preview($this->commissionInput($sale))),$this->user->id);$frozen=$first->fresh()->after;
            $this->assertSame('5.00',$first->after['total']);
            $this->travelTo(\Carbon\Carbon::parse('2026-11-05 12:00:00'));
            app(\App\Services\FinancialService::class)->pay($entry->id,'50','2026-11-05','pix',$this->user->id,(string)Str::uuid());
            $second=$service->close($this->commissionCloseInput($service->preview($this->commissionInput($sale))),$this->user->id);$this->assertSame('5.00',$second->after['total']);
            app(Checkout::class)->cancel($sale->id,$this->user->id,true);
            $preview=$service->preview($this->commissionInput($sale));$this->assertSame('-10.00',$preview['total']);
            try{$service->close($this->commissionCloseInput($preview),$this->user->id);$this->fail('Negative payout created.');}catch(ValidationException $e){$this->assertArrayHasKey('total',$e->errors());}
            $new=$this->commissionSale('200');$preview=$service->preview($this->commissionInput($new));$this->assertSame('-10.00',$preview['adjustments']);$this->assertSame('10.00',$preview['total']);
            $third=$service->close($this->commissionCloseInput($preview),$this->user->id);$this->assertCount(2,$third->after['lines']);$this->assertSame($frozen,$first->fresh()->after);
        }finally{$this->travelBack();}
    }
    public function test_commissions_accrual_and_stale_preview_and_legacy_receipts_are_distinct(): void
    {
        $sale=$this->commissionSale('100',false);$service=app(\App\Services\CommissionSettlements::class);$preview=$service->preview($this->commissionInput($sale));$this->assertSame('0.00',$preview['total']);
        $sale->update(['commission_basis'=>'accrual']);$preview=$service->preview($this->commissionInput($sale));$this->assertSame('10.00',$preview['total']);
        $sale->update(['commission_total'=>'12.00']);
        try{$service->close($this->commissionCloseInput($preview),$this->user->id);$this->fail('Stale preview accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('preview_hash',$e->errors());}
        $sale->update(['commission_basis'=>'cash']);$sale->entries()->first()->update(['legacy_paid_amount'=>'50.00','paid_amount'=>'50.00']);
        $this->assertSame(1,$service->preview($this->commissionInput($sale))['legacy_sales']);
    }
    public function test_seller_sees_only_own_commissions_and_cannot_close_pay_or_change_filter(): void
    {
        $sale=$this->commissionSale();$other=User::create(['name'=>'OTHER PRIVATE SELLER','email'=>'other-commission@example.test','password'=>'test-other-password','role'=>'operator','active'=>true]);$seller=\App\Models\Seller::create(['name'=>$other->name,'user_id'=>$other->id,'active'=>true]);
        $otherSale=$this->commissionSale('200',true,$seller->id);$service=app(\App\Services\CommissionSettlements::class);
        $closure=$service->close($this->commissionCloseInput($service->preview($this->commissionInput($otherSale))),$this->user->id);
        $this->user->update(['role'=>'operator']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->get('/app/a/my-commissions')->assertOk()->assertSee('Minhas comissões')->assertDontSee('OTHER PRIVATE SELLER')->assertViewHas('rows',fn($rows)=>$rows->count()===1&&$rows->first()->id===$sale->id);
        $this->get('/app/a/my-commissions?salesperson_id='.$seller->id)->assertForbidden();
        $this->get('/app/a/my-commissions/'.$closure->id)->assertForbidden();
        $this->get('/app/a/commissions')->assertForbidden();
        $this->post('/app/a/commissions/close',[])->assertForbidden();
        $this->post('/app/a/commissions/closures/'.$closure->id.'/pay',[])->assertForbidden();
        app(TenantConnection::class)->connect($this->a);$this->assertSame(1,$service->records()->count());$this->assertSame('0.00',FinancialEntry::find($closure->after['entry_id'])->paid_amount);
        $this->get('/app/b/my-commissions')->assertRedirect('/app/b/login');
    }
    public function test_concurrent_commission_closures_recognize_the_sale_once(): void
    {
        $sale=$this->commissionSale();$service=app(\App\Services\CommissionSettlements::class);$preview=$service->preview($this->commissionInput($sale));$a=$this->commissionCloseInput($preview);$b=$this->commissionCloseInput($preview);
        $attempt=function($input){try{return app(\App\Services\CommissionSettlements::class)->close($input,$this->user->id)->id;}catch(ValidationException){return 0;}};
        $results=$this->race(null,fn()=>$attempt($a),fn()=>$attempt($b));
        $this->assertCount(1,array_filter($results,fn($id)=>$id>0));$this->assertCount(1,array_filter($results,fn($id)=>$id===0));
        $this->assertSame(1,$service->records()->count());$this->assertSame(1,FinancialEntry::where('category','Comissões de vendas')->count());
    }
    public function test_commission_rounding_uses_cumulative_receipts_and_closed_cash_blocks_payout(): void
    {
        $sale=$this->commissionSale('100',false);$entry=$sale->entries()->firstOrFail();$service=app(\App\Services\CommissionSettlements::class);$totals=[];
        foreach(['33.33','33.33','33.34'] as $amount){
            app(\App\Services\FinancialService::class)->pay($entry->id,$amount,now()->toDateString(),'pix',$this->user->id,(string)Str::uuid());
            $closure=$service->close($this->commissionCloseInput($service->preview($this->commissionInput($sale))),$this->user->id);$totals[]=$closure->after['total'];
        }
        $this->assertSame(['3.33','3.34','3.33'],$totals);$this->assertSame('10.00',$service->balances($sale->salesperson_id)['closed']);
        app(\App\Services\CashService::class)->close(['request_key'=>(string)Str::uuid(),'date'=>now()->toDateString(),'opening_balance'=>'0','counted_balance'=>'0'],$this->user->id);
        try{$service->pay($closure->id,['request_key'=>(string)Str::uuid(),'amount'=>$closure->after['total'],'paid_on'=>now()->toDateString(),'payment_method'=>'pix'],$this->user->id);$this->fail('Closed cash payout accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('paid_on',$e->errors());}
        $this->assertSame('0.00',FinancialEntry::find($closure->after['entry_id'])->paid_amount);
    }

    public function test_named_payment_methods_keep_canonical_type_and_historical_labels_after_changes(): void
    {
        $catalog=app(\App\Services\PaymentMethods::class);$catalog->save(['version'=>0,'label'=>'Pix Sicoob','type'=>'pix','active'=>1],$this->user->id);$id=array_key_last($catalog->all());
        $sale=$this->commissionSale('100',true,null,['payment_option'=>$id,'payment_method'=>'cash']);$this->assertSame('pix',$sale->payment_method);
        $labels=$catalog->labels('sale_payment_method',[$sale->id]);$this->assertSame('Pix Sicoob',$labels[$sale->id]);
        $payment=\App\Models\Payment::where('entry_id',$sale->entries()->first()->id)->firstOrFail();$this->assertSame('Pix Sicoob',$catalog->labels('payment_method_used',[$payment->id])[$payment->id]);
        $version=\App\Models\CompanyOperation::find(1)->version;$catalog->save(['id'=>$id,'version'=>$version,'label'=>'Pix novo nome','type'=>'pix','active'=>0],$this->user->id);
        $this->assertSame('Pix Sicoob',$catalog->labels('sale_payment_method',[$sale->id])[$sale->id]);
        $breakdown=app(\App\Services\SalesBreakdown::class)->year((int)now()->year);$this->assertSame('Pix Sicoob',$breakdown['methods'][0]['label']);$this->assertSame('100.00',$breakdown['total']);$this->assertSame('100.00',$breakdown['methods'][0]['percent']);
        try{$this->commissionSale('100',true,null,['payment_option'=>$id]);$this->fail('Inactive method accepted.');}catch(ValidationException $e){$this->assertArrayHasKey('payment_option',$e->errors());}
        $version=\App\Models\CompanyOperation::find(1)->version;app(\App\Services\CompanyOperations::class)->save($this->operationPayload(['version'=>$version,'commissions'=>false]),$this->user->id);
        $this->assertArrayHasKey($id,$catalog->all());
    }
    public function test_checkout_resolves_named_payment_server_side_and_seller_cannot_manage_catalog(): void
    {
        $catalog=app(\App\Services\PaymentMethods::class);$catalog->save(['version'=>0,'label'=>'Cartão balcão','type'=>'debit','active'=>1],$this->user->id);$id=array_key_last($catalog->all());$this->product->update(['controls_stock'=>false]);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->post('/app/a/sales',['request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']],'paid'=>1,'payment_option'=>$id,'payment_method'=>'cash'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);$this->assertSame('debit',Sale::firstOrFail()->payment_method);
        $this->user->update(['role'=>'operator']);$this->get('/app/a/payment-methods')->assertForbidden();$this->post('/app/a/payment-methods',['version'=>1,'label'=>'Spoof','type'=>'cash','active'=>1])->assertForbidden();
    }

    public function test_split_checkout_preserves_receipts_installments_and_exact_total(): void
    {
        $key=(string)Str::uuid();
        $sale=$this->commissionSale('100',true,null,['request_key'=>$key,'payments'=>[
            ['payment_option'=>'pix','amount'=>'30.00','paid'=>1],
            ['payment_option'=>'credit','amount'=>'70.00','paid'=>0,'due_date'=>now()->addDay()->toDateString(),'installments'=>3],
        ]]);
        $this->assertFalse($sale->paid);$this->assertSame('30.00',(string)$sale->entries()->sum('paid_amount'));
        $this->assertSame(['30.00','23.33','23.33','23.34'],$sale->entries()->orderBy('id')->pluck('amount')->all());
        $this->assertSame('pix',\App\Models\Payment::firstOrFail()->method);
        $rows=app(\App\Services\SalePayments::class)->rows($sale->id);$this->assertSame('70.00',$rows[1]['amount']);
        $this->assertSame($sale->id,$this->commissionSale('100',true,null,['request_key'=>$key])->id);
        $this->assertSame(1,Sale::count());$this->assertSame(4,FinancialEntry::count());
        $summary=app(\App\Services\SalesBreakdown::class)->year((int)now()->year);
        $this->assertSame('100.00',$summary['total']);$this->assertSame('70.00',$summary['methods'][0]['total']);
        $this->assertSame('3.00',app(\App\Services\CommissionSettlements::class)->preview($this->commissionInput($sale))['total']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->get('/app/a/sales/'.$sale->id)->assertOk()->assertSee('Composição do pagamento');
        $this->postJson('/app/a/sales/'.$sale->id.'/receive',[])->assertUnprocessable();
    }

    public function test_split_paid_cash_and_pix_support_change_fiscal_evidence_and_refund(): void
    {
        $sale=$this->commissionSale('100',true,null,['payments'=>[
            ['payment_option'=>'cash','amount'=>'40','paid'=>1,'cash_received'=>'50'],
            ['payment_option'=>'pix','amount'=>'60','paid'=>1],
        ]]);
        $this->assertTrue($sale->paid);$this->assertSame('10.00',app(\App\Services\SalePayments::class)->rows($sale->id)[0]['change']);
        $evidence=app(\App\Fiscal\NfcePayload::class)->paymentEvidence($sale,['pix_mode'=>'static']);
        $this->assertSame([['forma_pagamento'=>'01','valor_pagamento'=>'40.00'],['forma_pagamento'=>'20','valor_pagamento'=>'60.00']],$evidence['methods']);
        $summary=app(\App\Services\CashService::class)->summary(now()->toDateString());
        $this->assertSame('40.00',$summary['cash_in']);$this->assertSame('60.00',$summary['totals_by_method']['pix']['in']);
        app(Checkout::class)->cancel($sale->id,$this->user->id,true);
        $this->assertSame(['cash','pix'],\App\Models\Payment::where('kind','refund')->orderBy('id')->pluck('method')->all());
        $this->assertSame('0.00',app(\App\Services\CashService::class)->summary(now()->toDateString())['totals_by_method']['pix']['net']);
    }

    public function test_split_invalid_totals_unknown_options_and_closed_day_roll_back_everything(): void
    {
        $this->product->update(['controls_stock'=>false]);
        foreach([
            [['payment_option'=>'cash','amount'=>'99','paid'=>1]],
            [['payment_option'=>'cash','amount'=>'100','paid'=>1,'cash_received'=>'90']],
            [['payment_option'=>'invoice','amount'=>'100','paid'=>1]],
            [['payment_option'=>'missing','amount'=>'100','paid'=>1]],
            [['payment_option'=>'pix','amount'=>'100','paid'=>0]],
        ] as $rows){
            try{$this->commissionSale('100',true,null,['payments'=>$rows]);$this->fail('Invalid split accepted.');}catch(ValidationException){$this->assertSame(0,Sale::count());$this->assertSame(0,FinancialEntry::count());$this->assertSame(0,\App\Models\Payment::count());}
        }
        app(\App\Services\CashService::class)->close(['request_key'=>(string)Str::uuid(),'date'=>now()->toDateString(),'opening_balance'=>'0','counted_balance'=>'0'],$this->user->id);
        try{$this->commissionSale('100',true,null,['payments'=>[['payment_option'=>'pix','amount'=>'100','paid'=>1]]]);$this->fail('Closed day accepted.');}catch(ValidationException){$this->assertSame(0,Sale::count());}
    }

    public function test_cash_opening_movements_and_counted_methods_are_immutable_and_authorized(): void
    {
        $cash=app(\App\Services\CashService::class);$today=now()->toDateString();
        $cash->open(['date'=>$today,'opening_balance'=>'50'],$this->user->id);
        $input=['request_key'=>(string)Str::uuid(),'type'=>'supply','amount'=>'20','description'=>'Reforço de troco'];
        $cash->movement($input,$this->user->id);$cash->movement($input,$this->user->id);
        $cash->movement(['request_key'=>(string)Str::uuid(),'type'=>'withdrawal','amount'=>'10','description'=>'Retirada para cofre'],$this->user->id);
        $this->assertSame('60.00',$cash->summary($today)['expected_balance']);$this->assertSame(2,\App\Models\Payment::count());
        try{$cash->movement(['request_key'=>(string)Str::uuid(),'type'=>'withdrawal','amount'=>'61','description'=>'Excedente'],$this->user->id);$this->fail('Excess withdrawal accepted.');}catch(ValidationException){$this->assertSame(2,FinancialEntry::count());}
        $close=['request_key'=>(string)Str::uuid(),'date'=>$today,'opening_balance'=>'50','counted_balance'=>'59','counted_methods'=>['pix'=>'0','credit'=>'2']];
        $closure=$cash->close($close,$this->user->id);$this->assertSame('-1.00',$closure->difference);$this->assertSame('2.00',$closure->snapshot['differences_by_method']['credit']);
        $this->assertSame($closure->id,$cash->close($close,$this->user->id)->id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);$this->get('/app/a/cash')->assertOk()->assertSee('Conferência dos demais meios');
        app(TenantConnection::class)->connect($this->a);$this->user->update(['role'=>'operator']);
        $this->post('/app/a/cash/open',['date'=>$today,'opening_balance'=>'50'])->assertForbidden();$this->post('/app/a/cash/movement',$input)->assertForbidden();
    }

    public function test_http_os_without_items_then_split_cashier_conversion_is_idempotent(): void
    {
        $this->product->update(['controls_stock'=>false]);$this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->post('/app/a/documents',['request_key'=>(string)Str::uuid(),'type'=>'work_order','asset'=>'Notebook sem itens'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);$doc=\App\Models\Document::firstOrFail();
        $this->assertSame([],$doc->items);$this->assertSame(0,FinancialEntry::count());
        app(Documents::class)->updateDraft($doc->id,['expected_hash'=>app(Documents::class)->editHash($doc),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']]],$this->user->id);
        foreach(['approved','in_progress','completed'] as $status)app(Documents::class)->transition($doc->id,$status);
        $this->get('/app/a/desk?document_number='.$doc->id)->assertRedirect(route('document.show',['company'=>'a','id'=>$doc->id]).'#document-checkout');
        $this->get('/app/a/documents/'.$doc->id)->assertOk()->assertSee('Dividir pagamento / entrada e saldo');
        $input=['payments'=>[['payment_option'=>'cash','amount'=>'40','paid'=>1],['payment_option'=>'pix','amount'=>'60','paid'=>1]]];
        $this->post('/app/a/documents/'.$doc->id.'/invoice',$input)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/app/a/documents/'.$doc->id.'/invoice',$input)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);$this->assertSame(1,Sale::count());$this->assertSame(2,\App\Models\Payment::count());$this->assertSame('invoiced',$doc->fresh()->status);
    }

    public function test_concurrent_split_checkout_and_cash_opening_are_recorded_once(): void
    {
        $this->product->update(['controls_stock'=>false]);
        $input=['request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']],'payments'=>[['payment_option'=>'cash','amount'=>'40','paid'=>1],['payment_option'=>'pix','amount'=>'60','paid'=>1]]];
        $ids=$this->race(null,fn()=>app(Checkout::class)->complete($input,$this->user->id)->id);
        $this->assertSame($ids[0],$ids[1]);$this->assertSame(1,Sale::count());$this->assertSame(2,FinancialEntry::count());$this->assertSame(2,\App\Models\Payment::count());
        $open=['date'=>now()->toDateString(),'opening_balance'=>'0'];
        $this->race(null,function()use($open){app(\App\Services\CashService::class)->open($open,$this->user->id);return 1;});
        $this->assertSame(1,\App\Models\OperationRecord::where('action','cash_opened')->count());
    }

    private function cardBrand(array $changes=[]): string
    {
        $service=app(\App\Services\CardFees::class);
        $service->save(array_replace(['version'=>\App\Models\CompanyOperation::find(1)?->version??0,'name'=>'Visa · contrato QA','type'=>'credit','policy'=>'absorb','active'=>1,'rates'=>[1=>'3.00',2=>'4.00',3=>'5.00']],$changes),$this->user->id);
        return array_key_last($service->all());
    }
    private function cardPayment(string $brand,array $changes=[]): array
    {
        return array_replace(['payment_option'=>'credit','amount'=>'100','paid'=>1,'card_brand'=>$brand,'card_installments'=>1,'fee_policy'=>'absorb','rate_seen'=>'3.00'],$changes);
    }
    public function test_card_fee_absorbed_and_passed_preserve_base_commission_and_net_receipts(): void
    {
        $brand=$this->cardBrand();$sale=$this->commissionSale('100',true,null,['payments'=>[$this->cardPayment($brand)]]);
        $this->assertSame('100.00',$sale->total);$this->assertSame('10.00',$sale->commission_total);
        $rows=app(\App\Services\SalePayments::class)->rows($sale->id);$this->assertSame('3.00',$rows[0]['fee']);$this->assertSame('97.00',$rows[0]['merchant_net']);
        $this->assertSame('3.00',FinancialEntry::where('direction','out')->firstOrFail()->paid_amount);
        $pass=$this->commissionSale('100',true,null,['payments'=>[$this->cardPayment($brand,['fee_policy'=>'pass','fee_reviewed'=>1,'charged_seen'=>'103.09'])]]);
        $this->assertSame('103.09',$pass->total);$this->assertSame('100.00',$pass->items->first()->total);$this->assertSame('10.00',$pass->commission_total);
        $row=app(\App\Services\SalePayments::class)->rows($pass->id)[0];$this->assertSame('3.09',$row['fee']);$this->assertSame('100.00',$row['merchant_net']);
        $summary=app(\App\Services\CashService::class)->summary(now()->toDateString());$this->assertSame('197.00',$summary['totals_by_method']['credit']['net']);
        $this->assertSame('10.00',app(\App\Services\CommissionSettlements::class)->preview($this->commissionInput($pass))['lines'][1]['eligible']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);$this->get('/app/a/sales/'.$pass->id)->assertOk()->assertSee('Acréscimo do pagamento em cartão')->assertSee('103,09');
    }
    public function test_single_card_http_and_document_card_surcharge_are_server_authoritative(): void
    {
        $brand=$this->cardBrand();$this->product->update(['controls_stock'=>false]);$this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $input=['request_key'=>(string)Str::uuid(),'payment_option'=>'credit','payment_method'=>'cash','paid'=>1,'card_brand'=>$brand,'card_installments'=>3,'fee_policy'=>'pass','rate_seen'=>'5.00','charged_seen'=>'105.26','fee_reviewed'=>1,'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']]];
        $this->post('/app/a/sales',$input)->assertRedirect()->assertSessionHasNoErrors();app(TenantConnection::class)->connect($this->a);
        $sale=Sale::firstOrFail();$this->assertSame('105.26',$sale->total);$this->assertSame('credit',$sale->payment_method);$this->assertTrue($sale->paid);
        $this->assertSame(3,app(\App\Services\SalePayments::class)->rows($sale->id)[0]['card_installments']);
        $doc=app(Documents::class)->create(['request_key'=>(string)Str::uuid(),'type'=>'quote','items'=>$input['items']],$this->user->id);app(Documents::class)->transition($doc->id,'approved');
        $converted=app(Documents::class)->invoice($doc->id,$input,$this->user->id);$this->assertSame('105.26',$converted->total);$this->assertSame('100.00',$doc->fresh()->total);
        $this->assertSame($converted->id,app(Documents::class)->invoice($doc->id,$input,$this->user->id)->id);
    }
    public function test_card_rate_review_undefined_installments_wrong_brand_and_stale_rates_roll_back(): void
    {
        $brand=$this->cardBrand();
        foreach([['fee_policy'=>'pass'],['fee_policy'=>'pass','fee_reviewed'=>1,'charged_seen'=>'103.00'],['card_installments'=>4],['rate_seen'=>'2.00'],['card_brand'=>'unknown'],['payment_option'=>'pix']] as $change){
            try{$this->commissionSale('100',true,null,['payments'=>[$this->cardPayment($brand,$change)]]);$this->fail('Unreviewed or incompatible fee accepted.');}catch(ValidationException){$this->assertSame(0,Sale::count());$this->assertSame(0,FinancialEntry::count());}
        }
        $this->expectException(ValidationException::class);$this->commissionSale('100',true,null,['payment_method'=>'credit']);
    }
    public function test_card_fee_partial_sale_charges_only_card_part_and_keeps_historical_contract(): void
    {
        $brand=$this->cardBrand();$input=['payments'=>[['payment_option'=>'cash','amount'=>'50','paid'=>1],$this->cardPayment($brand,['amount'=>'50','paid'=>0,'card_installments'=>3,'rate_seen'=>'5.00','fee_policy'=>'pass','fee_reviewed'=>1,'charged_seen'=>'52.63','due_date'=>now()->addDay()->toDateString()])]];
        $sale=$this->commissionSale('100',true,null,$input);$this->assertSame('102.63',$sale->total);$this->assertFalse($sale->paid);
        $this->assertSame(['50.00','17.54','17.54','17.55'],$sale->entries()->orderBy('id')->pluck('amount')->all());
        $rows=app(\App\Services\SalePayments::class)->rows($sale->id);$this->assertSame('2.63',$rows[1]['fee']);$this->assertSame('50.00',$rows[1]['merchant_net']);
        $feeIds=$rows[1]['fee_entry_ids'];$this->assertSame('2.63',(string)FinancialEntry::whereIn('id',$feeIds)->sum('amount'));$this->assertSame('0.00',(string)FinancialEntry::whereIn('id',$feeIds)->sum('paid_amount'));
        $this->cardBrand(['id'=>$brand,'name'=>'Contrato alterado','active'=>0,'rates'=>[1=>'10.00']]);
        $this->assertSame('Visa · contrato QA',app(\App\Services\SalePayments::class)->rows($sale->id)[1]['brand_name']);
        app(Checkout::class)->cancel($sale->id,$this->user->id,true);$this->assertSame(count($feeIds),FinancialEntry::whereIn('id',$feeIds)->whereNotNull('cancelled_at')->count());
    }
    public function test_card_brand_catalog_permissions_optimistic_version_and_debit_rate_validation(): void
    {
        $brand=$this->cardBrand();$version=\App\Models\CompanyOperation::find(1)->version;
        try{app(\App\Services\CardFees::class)->save(['version'=>0,'name'=>'Stale','type'=>'credit','policy'=>'absorb','active'=>1,'rates'=>[1=>'2']],$this->user->id);$this->fail('Stale config accepted.');}catch(ValidationException){$this->assertCount(1,app(\App\Services\CardFees::class)->all());}
        try{$this->cardBrand(['name'=>'Débito','type'=>'debit','rates'=>[2=>'1']]);$this->fail('Debit installment accepted.');}catch(ValidationException){$this->assertSame($version,\App\Models\CompanyOperation::find(1)->version);}
        $debit=$this->cardBrand(['name'=>'Débito','type'=>'debit','rates'=>[1=>'0.00']]);$this->assertSame('0.00',app(\App\Services\CardFees::class)->all()[$debit]['rates'][1]);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);$this->get('/app/a/payment-methods')->assertOk()->assertSee('Bandeiras e taxas por parcela');
        app(TenantConnection::class)->connect($this->a);$this->user->update(['role'=>'operator']);$this->post('/app/a/payment-methods/card-brands',[])->assertForbidden();
    }
    public function test_concurrent_card_fee_sale_records_one_fee_expense_and_blocks_incomplete_fiscal_total(): void
    {
        $brand=$this->cardBrand();$this->product->update(['controls_stock'=>false]);$input=['request_key'=>(string)Str::uuid(),'payments'=>[$this->cardPayment($brand,['fee_policy'=>'pass','fee_reviewed'=>1,'charged_seen'=>'103.09'])],'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']]];
        $ids=$this->race(null,fn()=>app(Checkout::class)->complete($input,$this->user->id)->id);$this->assertSame($ids[0],$ids[1]);$this->assertSame(2,FinancialEntry::count());$this->assertSame(2,\App\Models\Payment::count());
        $sale=Sale::findOrFail($ids[0]);try{app(\App\Fiscal\NfcePayload::class)->build($this->a,$sale,new \App\Models\FiscalIssuer,[]);$this->fail('Surcharge omitted from fiscal total.');}catch(ValidationException $e){$this->assertStringContainsString('acréscimo',$e->errors()['fiscal'][0]);}
        app(Checkout::class)->cancel($sale->id,$this->user->id,true);$this->assertSame('3.09',FinancialEntry::where('direction','out')->firstOrFail()->paid_amount);
    }

    private function quickItem(array $changes=[]): array
    {return array_replace(['request_key'=>(string)Str::uuid(),'name'=>'Item rápido QA','product_group_id'=>$this->product->product_group_id,'unit'=>'UN','sale_price'=>'75.00','controls_stock'=>0],$changes);}
    public function test_quick_customer_is_saved_selected_by_id_and_retries_do_not_duplicate(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);$data=['request_key'=>(string)Str::uuid(),'name'=>'Cliente rápido','document'=>'529.982.247-25','phone'=>'35999999999'];
        $response=$this->postJson('/app/a/quick-create/customers',$data)->assertOk()->assertJsonPath('item.name','Cliente rápido');$id=$response->json('item.id');
        $this->postJson('/app/a/quick-create/customers',$data)->assertOk()->assertJsonPath('item.id',$id);
        $this->postJson('/app/a/quick-create/customers',array_replace($data,['name'=>'Outro nome']))->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->postJson('/app/a/quick-create/customers',array_replace($data,['request_key'=>(string)Str::uuid()]))->assertUnprocessable()->assertJsonValidationErrors('document');
        app(TenantConnection::class)->connect($this->a);$this->assertSame(1,\App\Models\Customer::count());$this->assertSame('52998224725',\App\Models\Customer::findOrFail($id)->document);$this->assertSame(0,Sale::count());
    }
    public function test_quick_product_stock_and_service_reuse_catalog_rules_without_creating_sale(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);$input=$this->quickItem(['controls_stock'=>1,'initial_quantity'=>'2','initial_cost'=>'30']);
        $response=$this->postJson('/app/a/quick-create/products',$input)->assertOk()->assertJsonPath('item.type','product')->assertJsonPath('item.quantity','2.000');$id=$response->json('item.id');
        $this->postJson('/app/a/quick-create/products',$input)->assertOk()->assertJsonPath('item.id',$id);
        app(TenantConnection::class)->connect($this->a);$this->assertSame('30.0000',Product::findOrFail($id)->average_cost);$this->assertSame(1,StockMovement::where('product_id',$id)->count());
        $this->assertSame(0,Sale::count());$this->assertSame(0,FinancialEntry::count());
        $service=$this->postJson('/app/a/quick-create/services',$this->quickItem(['name'=>'Análise técnica','sale_price'=>'0']))->assertOk()->assertJsonPath('item.type','service')->assertJsonPath('item.price','0.00');
        app(TenantConnection::class)->connect($this->a);$this->assertFalse(Product::findOrFail($service->json('item.id'))->controls_stock);
        $this->get('/app/a/desk')->assertOk()->assertSee('quick-create-dialog')->assertSee('Novo produto')->assertSee('Novo cliente');
        $this->get('/app/a/documents/new?type=work_order')->assertOk()->assertSee('quick-create-dialog');
    }
    public function test_quick_permissions_do_not_grant_stock_or_update_existing_products(): void
    {
        $profile=$this->accessProfile(['products.manage','customers.manage','sales.create'],'Cadastro no atendimento');$operator=$this->profiledOperator($profile);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id]);
        $this->postJson('/app/a/quick-create/products',$this->quickItem(['controls_stock'=>1,'initial_quantity'=>'1','initial_cost'=>'10']))->assertForbidden();
        $created=$this->postJson('/app/a/quick-create/products',$this->quickItem(['id'=>$this->product->id,'name'=>'Novo sem substituir']))->assertOk();
        app(TenantConnection::class)->connect($this->a);$this->assertNotSame($this->product->id,$created->json('item.id'));$this->assertSame('Produto teste',$this->product->fresh()->name);$this->assertSame(0,StockMovement::count());
        $this->user->update(['role'=>'operator']);$this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->postJson('/app/a/quick-create/customers',['request_key'=>(string)Str::uuid(),'name'=>'Negado'])->assertForbidden();$this->postJson('/app/a/quick-create/services',$this->quickItem())->assertForbidden();
        $this->get('/app/a/desk')->assertOk()->assertDontSee('data-quick-create=',false);
    }
    public function test_quick_validation_rolls_back_and_respects_catalog_modes_and_duplicates(): void
    {
        $quick=app(\App\Services\QuickCreate::class);
        try{$quick->create('products',$this->quickItem(['sku'=>'SKU-1','controls_stock'=>1,'initial_quantity'=>'2','initial_cost'=>'5']),$this->user->id);$this->fail('Duplicate SKU accepted.');}catch(ValidationException){$this->assertSame(1,Product::count());$this->assertSame(0,StockMovement::count());}
        foreach(['ABC','11111111111'] as $document){try{$quick->create('customers',['request_key'=>(string)Str::uuid(),'name'=>'Inválido','document'=>$document],$this->user->id);$this->fail('Invalid taxpayer document accepted.');}catch(ValidationException){$this->assertSame(0,\App\Models\Customer::count());}}
        $this->product->update(['active'=>false]);
        app(\App\Services\CompanyOperations::class)->save($this->operationPayload(['catalog_mode'=>'services','inventory'=>false,'nfce'=>false,'nfe'=>false]),$this->user->id);
        try{$quick->create('products',$this->quickItem(),$this->user->id);$this->fail('Disabled catalog type accepted.');}catch(ValidationException){$this->assertSame(1,Product::count());}
    }
    public function test_concurrent_quick_customer_and_product_retries_create_one_record(): void
    {
        $quick=app(\App\Services\QuickCreate::class);$input=['request_key'=>(string)Str::uuid(),'name'=>'Concorrente'];
        $ids=$this->race(null,fn()=>$quick->create('customers',$input,$this->user->id)['id']);$this->assertSame($ids[0],$ids[1]);$this->assertSame(1,\App\Models\Customer::count());
        $item=$this->quickItem(['controls_stock'=>1,'initial_quantity'=>'1','initial_cost'=>'10']);$ids=$this->race(null,fn()=>$quick->create('products',$item,$this->user->id)['id']);$this->assertSame($ids[0],$ids[1]);$this->assertSame(1,StockMovement::count());
    }

    private function operationPayload(array $changes=[]): array
    {
        return array_replace(\App\Services\CompanyOperations::DEFAULTS,['version'=>0],$changes);
    }

    public function test_company_configuration_is_isolated_audited_and_rejects_stale_writes(): void
    {
        $ops=app(\App\Services\CompanyOperations::class);
        $row=$ops->save($this->operationPayload(['commissions'=>false]),$this->user->id);
        $this->assertSame(1,$row->version);
        $this->assertFalse($ops->current()['commissions']);
        $this->assertSame(1,DB::connection('tenant')->table('company_operation_audits')->count());
        try {$ops->save($this->operationPayload(['inventory'=>false]),$this->user->id);$this->fail('Stale write accepted');}
        catch (ValidationException $e) {$this->assertArrayHasKey('version',$e->errors());}
        app(TenantConnection::class)->connect($this->b);
        $this->assertTrue($ops->current()['commissions']);
        $this->assertSame(0,DB::connection('tenant')->table('company_operation_audits')->count());
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse($ops->current()['commissions']);
    }

    public function test_configuration_rejects_incompatible_modes_and_unreconciled_stock(): void
    {
        $ops=app(\App\Services\CompanyOperations::class);
        app(Inventory::class)->move($this->product->id,'in','2','60','Entrada',$this->user->id,(string)Str::uuid());
        foreach ([$this->operationPayload(['inventory'=>false]),$this->operationPayload(['catalog_mode'=>'services'])] as $payload) {
            try {$ops->save($payload,$this->user->id);$this->fail('Unsafe settings accepted');}
            catch (ValidationException $e) {$this->assertNotEmpty($e->errors());}
        }
        $this->assertTrue($ops->current()['inventory']);
        $this->assertSame('2.000',$this->product->fresh()->quantity);
    }

    public function test_disabled_inventory_does_not_move_stock_and_discounts_commissions_are_enforced(): void
    {
        $this->product->group->update(['commission_percent'=>'10','discount_enabled'=>true,'max_discount_percent'=>'20']);
        $ops=app(\App\Services\CompanyOperations::class);
        $ops->save($this->operationPayload(['inventory'=>false,'commissions'=>false,'discounts'=>false]),$this->user->id);
        $data=['request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']], 'payment_method'=>'pix','paid'=>false,'due_date'=>now()->addDay()->toDateString()];
        $sale=app(Checkout::class)->complete($data,$this->user->id);
        $this->assertSame('0.00',$sale->commission_total);
        $this->assertSame(0,StockMovement::count());
        $this->assertSame('0.000',$this->product->fresh()->quantity);
        $data['request_key']=(string)Str::uuid();$data['items'][0]['discount_percent']='5';
        try {app(Checkout::class)->complete($data,$this->user->id);$this->fail('Discount accepted');}
        catch (ValidationException $e) {$this->assertArrayHasKey('items',$e->errors());}
        $this->assertSame(1,Sale::count());
    }

    public function test_commission_basis_is_frozen_and_report_supports_both_regimes(): void
    {
        $this->product->group->update(['commission_percent'=>'10']);
        $ops=app(\App\Services\CompanyOperations::class);
        $ops->save($this->operationPayload(['inventory'=>false,'commission_basis'=>'accrual']),$this->user->id);
        $data=['request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100']], 'payment_method'=>'pix','paid'=>false,'due_date'=>now()->addDay()->toDateString()];
        $accrual=app(Checkout::class)->complete($data,$this->user->id);
        $this->assertSame('accrual',$accrual->commission_basis);
        $ops->save($this->operationPayload(['inventory'=>false,'commission_basis'=>'cash','version'=>1]),$this->user->id);
        $data['request_key']=(string)Str::uuid();
        $cash=app(Checkout::class)->complete($data,$this->user->id);
        $this->assertSame('cash',$cash->commission_basis);
        $this->assertSame('accrual',$accrual->fresh()->commission_basis);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->get('/app/a/commissions')->assertOk()->assertViewHas('totals',fn($t)=>$t['commission']==='20.00'&&$t['eligible']==='10.00'&&$t['awaiting']==='10.00');
    }

    public function test_setup_blocks_completion_until_company_is_ready_then_invalidates_after_change(): void
    {
        $ops=app(\App\Services\CompanyOperations::class);
        $ops->save($this->operationPayload(['nfce'=>false,'nfe'=>false,'nfse'=>false]),$this->user->id);
        try {$ops->complete(1,$this->user->id);$this->fail('Incomplete setup accepted');}
        catch (ValidationException $e) {$this->assertArrayHasKey('configuration',$e->errors());}
        \App\Models\BusinessProfile::forceCreate(['id'=>1,'legal_name'=>'Empresa Teste','document'=>'07.396.600/0001-60']);
        $ops->complete(1,$this->user->id);
        $this->assertNotNull(\App\Models\CompanyOperation::find(1)->completed_at);
        $ops->save($this->operationPayload(['nfce'=>false,'nfe'=>false,'nfse'=>false,'commissions'=>false,'version'=>2]),$this->user->id);
        $this->assertNull(\App\Models\CompanyOperation::find(1)->completed_at);
        $ops->complete(3,$this->user->id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->post('/app/a/business',['legal_name'=>'Empresa Atualizada','document'=>'07.396.600/0001-60'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertNull(\App\Models\CompanyOperation::find(1)->completed_at);
        $this->assertSame('company_changed',DB::connection('tenant')->table('company_operation_audits')->latest('id')->value('action'));
    }

    public function test_disabled_documents_and_fiscal_issuance_are_rejected_without_hiding_history(): void
    {
        $ops=app(\App\Services\CompanyOperations::class);
        $ops->save($this->operationPayload(['quotes'=>false,'work_orders'=>false,'nfce'=>false,'nfe'=>false,'nfse'=>false]),$this->user->id);
        try {app(Documents::class)->create(['type'=>'work_order','request_key'=>(string)Str::uuid()],$this->user->id);$this->fail('Disabled OS accepted');}
        catch (ValidationException $e) {$this->assertArrayHasKey('configuration',$e->errors());}
        foreach ([\App\Fiscal\QueueNfce::class,\App\Fiscal\QueueNfe::class] as $queue) {
            try {app($queue)->enqueue($this->a,999,$this->user->id,[]);$this->fail('Disabled fiscal accepted');}
            catch (ValidationException $e) {$this->assertArrayHasKey('configuration',$e->errors());}
        }
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->get('/app/a/documents')->assertOk()->assertDontSee('+ Ordem de serviço');
        $this->get('/app/a/documents/new?type=work_order')->assertSessionHasErrors('configuration');
    }
    public function test_services_only_filters_catalog_and_blocks_product_sale_in_domain(): void
    {
        $this->product->update(['active'=>false]);
        $service=Product::create(['name'=>'Manutenção','sku'=>'SRV','type'=>'service','controls_stock'=>false,'reference_cost'=>'20','product_group_id'=>$this->product->product_group_id,'sale_price'=>'100','unit'=>'UN']);
        $ops=app(\App\Services\CompanyOperations::class);
        $ops->save($this->operationPayload(['catalog_mode'=>'services','inventory'=>false,'nfce'=>false,'nfe'=>false]),$this->user->id);
        $data=['request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$service->id,'quantity'=>'1','unit_price'=>'100']], 'payment_method'=>'pix','paid'=>false,'due_date'=>now()->addDay()->toDateString()];
        $sale=app(Checkout::class)->complete($data,$this->user->id);
        $this->assertSame('20.0000',$sale->cost_total);
        $this->assertSame('service',$sale->items->first()->fiscal_kind);
        $this->assertSame(0,StockMovement::count());
        try {$ops->assertItem('product');$this->fail('Excluded type accepted');}
        catch (ValidationException $e) {$this->assertArrayHasKey('items',$e->errors());}
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0])->get('/app/a/sales/new')->assertOk()->assertViewHas('products',fn($items)=>$items->count()===1&&$items->first()->id===$service->id);
    }

    public function test_disabling_documents_rejects_pending_work_and_preserves_approved_discount(): void
    {
        $ops=app(\App\Services\CompanyOperations::class);
        $this->product->group->update(['discount_enabled'=>true,'max_discount_percent'=>'20']);
        $document=app(Documents::class)->create(['type'=>'quote','request_key'=>(string)Str::uuid(),'items'=>[['kind'=>'product','id'=>$this->product->id,'quantity'=>'1','unit_price'=>'100','discount_percent'=>'10']]],$this->user->id);
        try {$ops->save($this->operationPayload(['quotes'=>false]),$this->user->id);$this->fail('Pending document ignored');}
        catch (ValidationException $e) {$this->assertArrayHasKey('quotes',$e->errors());}
        $ops->save($this->operationPayload(['discounts'=>false,'inventory'=>false]),$this->user->id);
        $document->update(['status'=>'approved']);
        $sale=app(Checkout::class)->completeFromDocument($document->id,['payment_method'=>'pix','paid'=>false,'due_date'=>now()->addDay()->toDateString()],$this->user->id);
        $this->assertSame('90.00',$sale->total);
        $this->assertSame('10.00',$sale->discount_total);
    }
}
