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
                if ($table === 'migrations') {
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
        foreach (['/app/a', '/app/a/products', '/app/a/products/new', '/app/a/catalog/groups', '/app/a/catalog/customers', '/app/a/catalog/services', '/app/a/stock', '/app/a/sales', '/app/a/sales/new', '/app/a/users', '/app/a/catalog/suppliers', '/app/a/documents', '/app/a/documents/new?type=quote', '/app/a/documents/new?type=work_order', '/app/a/finance', '/app/a/purchases', '/app/a/purchases/new', '/app/a/reports', '/app/a/account/password', '/app/a/business'] as $path) {
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
        Service::create(['name' => 'Serviço de balcão', 'price' => '50.00', 'cost' => '17.43']);
        $this->user->update(['role' => 'operator']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a')->assertOk()->assertDontSee('Resultado bruto das vendas');
        $this->get('/app/a/products')->assertOk()->assertDontSee('Custo médio (R$)')->assertDontSee('60,0000');
        $this->get('/app/a/stock')->assertOk()->assertDontSee('<th class="num">Custo unitário</th>', false)->assertDontSee('60.0000');
        $this->get('/app/a/catalog/services')->assertOk()->assertDontSee('Custo (R$)')->assertDontSee('17.43');
        $this->get('/app/a/catalog/groups')->assertOk()->assertDontSee('Margem padrão (%)');
        $this->get('/app/a/finance')->assertForbidden();
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

        $sales = $this->get('/app/a/reports/export/sales?from=2026-11-01&to=2026-11-30')->assertOk();
        $rows = $this->csvRows($sales->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertSame((string) $current->id, $rows[1][0]);

        $payments = $this->get('/app/a/reports/export/payments?from=2026-10-01&to=2026-10-31')->assertOk();
        $rows = $this->csvRows($payments->streamedContent());
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

    private function csvRows(string $csv): array
    {
        $lines = preg_split('/\r?\n/', rtrim(substr($csv, 3), "\r\n"));
        return array_map(fn ($line) => str_getcsv($line, ';', '"', ''), $lines);
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

    /** Two separate MySQL connections reach the initial idempotency read together. */
    private function race(string $table, \Closure $operation): array
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
                    $reachedBarrier = false;
                    DB::listen(function ($query) use ($table, $directory, $worker, &$reachedBarrier) {
                        if ($reachedBarrier || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, '`'.$table.'`') || ! str_contains($query->sql, 'request_key')) {
                            return;
                        }
                        $reachedBarrier = true;
                        file_put_contents($directory.'/ready-'.$worker, 'ready');
                        $deadline = microtime(true) + 15;
                        while (count(glob($directory.'/ready-*')) < 2) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Timeout na barreira concorrente.');
                            }
                            usleep(10000);
                        }
                    });
                    try {
                        $result = ['id' => $operation()];
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
