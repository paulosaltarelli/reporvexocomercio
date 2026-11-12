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
        Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
        foreach (['a', 'b'] as $slug) {
            $t = Tenant::create(['slug' => $slug, 'name' => 'Empresa '.$slug, 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_'.$slug, 'db_username' => 'vendra_test', 'db_password' => env('VENDRA_TEST_PASSWORD')]);
            app(TenantConnection::class)->connect($t);
            Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            $this->$slug = $t;
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
        }parent::tearDown();
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
        app(Checkout::class)->cancel($s->id, $this->user->id);
        app(Checkout::class)->cancel($s->id, $this->user->id);
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
        foreach (['/app/a', '/app/a/products', '/app/a/products/new', '/app/a/catalog/groups', '/app/a/catalog/customers', '/app/a/catalog/services', '/app/a/stock', '/app/a/sales', '/app/a/sales/new', '/app/a/users', '/app/a/catalog/suppliers', '/app/a/documents', '/app/a/documents/new?type=quote', '/app/a/documents/new?type=work_order', '/app/a/finance'] as $path) {
            $this->get($path)->assertOk();
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
        $this->get('/')->assertOk()->assertSee('vendra');
    }

    public function test_http_catalog_stock_sale_and_receipt_workflow(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->post('/app/a/catalog/groups', ['name' => 'Informática', 'margin' => '40'])->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
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
        $this->post('/app/a/sales/'.$sale->id.'/cancel')->assertRedirect();
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
        app(Documents::class)->transition($d->id, 'approved');
        $payment = ['payment_method' => 'pix', 'paid' => true];
        $sale = app(Documents::class)->invoice($d->id, $payment, $this->user->id);
        $this->assertSame($sale->id, app(Documents::class)->invoice($d->id, $payment, $this->user->id)->id);
        $this->assertSame('3.000', $this->product->fresh()->quantity);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])->get('/app/a/documents/'.$d->id)->assertOk();
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
        foreach (['approved', 'in_progress', 'completed'] as $status) {
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
        $this->post('/app/a/finance/'.$e->id.'/pay')->assertRedirect();
        app(TenantConnection::class)->connect($this->a);
        $this->assertNotNull($e->fresh()->paid_at);
    }
}
