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
        foreach (['/app/a', '/app/a/products', '/app/a/products/new', '/app/a/catalog/groups', '/app/a/catalog/customers', '/app/a/products?type=service', '/app/a/stock', '/app/a/sales', '/app/a/sales/new', '/app/a/users', '/app/a/catalog/suppliers', '/app/a/documents', '/app/a/documents/new?type=quote', '/app/a/documents/new?type=work_order', '/app/a/finance', '/app/a/purchases', '/app/a/purchases/new', '/app/a/reports', '/app/a/account/password', '/app/a/business', '/app/a/cash', '/app/a/cashflow', '/app/a/prices'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/app/a/catalog/services')->assertRedirect('/app/a/products?type=service');
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
        $this->get('/')->assertOk()->assertSee('vendra')->assertSee('https://wa.me/5535999193304', false)->assertSee('99919-3304')->assertSee('mailto:ivancasvilela@gmail.com', false)->assertSee('/app/vexo/login', false)->assertSee('Exemplo ilustrativo');
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
}
