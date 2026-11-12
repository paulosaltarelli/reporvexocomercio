<?php

namespace Tests\Feature;

use App\Fiscal\FiscalReadiness;
use App\Fiscal\PrepareFiscalReview;
use App\Fiscal\Snapshot;
use App\Models\BusinessProfile;
use App\Models\FiscalPreparation;
use App\Models\FiscalSetting;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checkout;
use App\Services\Inventory;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FiscalTest extends TestCase
{
    private Tenant $tenant;

    private User $user;

    private Product $product;

    private Sale $sale;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure MySQL dedicado conforme docs/TESTES.md.');
        }
        config(['database.default' => 'central', 'database.connections.central' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'vendra_test_central', 'username' => 'vendra_test', 'password' => env('VENDRA_TEST_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
        DB::purge('central');
        $this->assertTestDatabase('central', 'vendra_test_central');
        Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
        foreach (['a', 'b'] as $slug) {
            $tenant = Tenant::create(['slug' => $slug, 'name' => 'Teste '.$slug, 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_'.$slug, 'db_username' => 'vendra_test', 'db_password' => env('VENDRA_TEST_PASSWORD')]);
            app(TenantConnection::class)->connect($tenant);
            $this->assertTestDatabase('tenant', 'vendra_t_test_'.$slug);
            Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            if ($slug === 'a') {
                $this->tenant = $tenant;
            }
        }
        app(TenantConnection::class)->connect($this->tenant);
        $this->user = User::create(['name' => 'Admin', 'email' => 'fiscal@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $group = ProductGroup::create(['name' => 'Teste', 'margin' => 20]);
        $this->product = Product::create(['name' => 'Mercadoria', 'sku' => 'FISCAL', 'product_group_id' => $group->id, 'unit' => 'UN', 'sale_price' => '10.01']);
        app(Inventory::class)->move($this->product->id, 'in', '10', '2', 'Teste', $this->user->id, (string) Str::uuid());
        $service = Service::create(['name' => 'Instalação', 'price' => '5.02', 'cost' => '1']);
        $this->sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true, 'items' => [
            ['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01'],
            ['kind' => 'service', 'id' => $service->id, 'quantity' => '1', 'unit_price' => '5.02'],
        ]], $this->user->id);
    }

    private function assertTestDatabase(string $name, string $expected): void
    {
        $connection = DB::connection($name);
        $allowed = ['vendra_test_central', 'vendra_t_test_a', 'vendra_t_test_b'];
        if (! in_array($expected, $allowed, true) || $connection->getDatabaseName() !== $expected || $connection->getConfig('username') !== 'vendra_test' || $connection->selectOne('SELECT DATABASE() AS db')->db !== $expected) {
            throw new \RuntimeException('Fixture fiscal recusada fora dos bancos dedicados.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            app(TenantConnection::class)->disconnect();
            DB::purge('central');
        }
        parent::tearDown();
    }

    private function login(): void
    {
        $this->withSession(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
    }

    private function settings(): array
    {
        return ['environment' => 'homologation', 'provider' => 'focus', 'nfce_accredited' => '0', 'operation' => 'both', 'accountant_name' => 'Contador de teste'];
    }

    public function test_admin_can_configure_and_review_but_cannot_enable_production(): void
    {
        $this->login();
        $this->get('/app/a/fiscal')->assertOk()->assertSee('Transmissão bloqueada');
        $this->post('/app/a/fiscal/settings', $this->settings())->assertRedirect();
        $this->get('/app/a/sales/'.$this->sale->id.'/fiscal')->assertOk()->assertSee('10,01')->assertSee('5,02');
        $this->post('/app/a/fiscal/settings', array_replace($this->settings(), ['environment' => 'production']))->assertSessionHasErrors('environment');
        $this->post('/app/a/fiscal/settings', $this->settings() + ['token' => 'secret-test-only', 'csc' => 'csc-test-only'])->assertSessionHasErrors(['token', 'csc'])->assertSessionMissing('_old_input.token')->assertSessionMissing('_old_input.csc');
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame('homologation', FiscalSetting::find(1)->environment);
        $this->assertTrue($this->tenant->fresh()->fiscal_demonstration);
    }

    public function test_snapshot_is_idempotent_frozen_and_has_no_commercial_or_fiscal_side_effect(): void
    {
        $before = [];
        foreach (['stock_movements', 'financial_entries', 'payments', 'fiscal_outbox', 'fiscal_documents', 'fiscal_sequences'] as $table) {
            $before[$table] = DB::connection('tenant')->table($table)->count();
        }
        $key = (string) Str::uuid();
        $preparation = app(PrepareFiscalReview::class)->prepare($this->tenant, $this->sale->id, $this->user->id, $key);
        $this->product->update(['sale_price' => '999', 'name' => 'Novo nome']);
        $this->assertSame($preparation->id, app(PrepareFiscalReview::class)->prepare($this->tenant, $this->sale->id, $this->user->id, $key)->id);
        $saved = $preparation->fresh();
        $this->assertSame('10.01', $saved->snapshot['totals']['goods']);
        $this->assertSame('5.02', $saved->snapshot['totals']['services']);
        $this->assertSame('15.03', $saved->snapshot['sale']['total']);
        $this->assertSame($saved->snapshot_sha256, Snapshot::hash($saved->snapshot));
        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::connection('tenant')->table($table)->count(), $table);
        }
        $this->assertSame(1, FiscalPreparation::count());
        $this->login();
        $this->get('/app/a/fiscal/preparations/'.$saved->id)->assertOk()->assertSee('Não houve emissão fiscal.');
    }

    public function test_operator_cannot_read_or_write_fiscal_configuration_or_snapshots(): void
    {
        $this->user->update(['role' => 'operator']);
        $this->login();
        $this->get('/app/a/fiscal')->assertForbidden();
        $this->post('/app/a/fiscal/settings', $this->settings())->assertForbidden();
        $this->get('/app/a/sales/'.$this->sale->id.'/fiscal')->assertForbidden();
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/prepare', ['request_key' => (string) Str::uuid()])->assertForbidden();
        $this->get('/app/a/fiscal/preparations/1')->assertForbidden();
    }

    public function test_tenant_cannot_read_another_preparation_or_use_client_tenant_id(): void
    {
        $record = app(PrepareFiscalReview::class)->prepare($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
        $other = Tenant::where('slug', 'b')->firstOrFail();
        app(TenantConnection::class)->connect($other);
        $user = User::create(['name' => 'Admin B', 'email' => 'b@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $user->id])->get('/app/b/fiscal/preparations/'.$record->id)->assertNotFound();
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $user->id])->post('/app/b/fiscal/settings', $this->settings() + ['tenant_id' => $this->tenant->id])->assertSessionHasErrors('tenant_id');
        app(TenantConnection::class)->connect($other);
        $this->assertSame(0, FiscalPreparation::count());
        $this->expectException(ValidationException::class);
        app(FiscalReadiness::class)->inspect($this->tenant);
    }

    public function test_issuer_is_read_from_current_tenant_profile_and_uf_mismatch_blocks(): void
    {
        BusinessProfile::create(['id' => 1, 'legal_name' => 'Teste CNPJ alfa', 'document' => '12.ABC.345/01DE-35', 'state' => 'SP', 'municipality_code' => '3106200']);
        $report = app(FiscalReadiness::class)->inspect($this->tenant, $this->sale);
        $codes = array_column($report['blockers'], 'code');
        $this->assertFalse($report['ready_to_transmit']);
        $this->assertNotContains('issuer.document', $codes);
        $this->assertContains('issuer.municipality_code', $codes);
        $this->assertContains('tax.validation', $codes);
        $this->assertContains('sale.services', $codes);
        $this->assertContains('production.demonstration', $codes);
        $this->assertSame('Teste CNPJ alfa', $report['snapshot']['issuer']['legal_name']);
    }

    public function test_cancelled_sale_cannot_create_preparation(): void
    {
        $this->sale->update(['status' => 'cancelled']);
        $this->login();
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/prepare', ['request_key' => (string) Str::uuid()])->assertSessionHasErrors('sale');
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame(0, FiscalPreparation::count());
    }

    public function test_record_cannot_be_changed_and_tampering_is_detected(): void
    {
        $record = app(PrepareFiscalReview::class)->prepare($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
        try {
            $record->update(['state' => 'authorized']);
            $this->fail('Update should fail');
        } catch (\LogicException) {
            $this->assertSame('review_required', $record->fresh()->state);
        }
        DB::connection('tenant')->table('fiscal_preparations')->where('id', $record->id)->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->login();
        $this->get('/app/a/fiscal/preparations/'.$record->id)->assertStatus(409);
    }

    public function test_concurrent_requests_create_one_review_without_reserving_numbers(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concorrência fiscal exige pcntl e MySQL reais.');
        }
        $directory = sys_get_temp_dir().'/vendra-fiscal-race-'.Str::uuid();
        mkdir($directory, 0700);
        $key = (string) Str::uuid();
        $children = [];
        DB::purge('central');
        DB::purge('tenant');
        try {
            for ($worker = 0; $worker < 2; $worker++) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('Falha ao criar worker fiscal.');
                }
                if ($pid === 0) {
                    try {
                        app(TenantConnection::class)->connect($this->tenant);
                        file_put_contents($directory.'/ready-'.$worker, 'ready');
                        $deadline = microtime(true) + 10;
                        while (count(glob($directory.'/ready-*')) < 2) {
                            if (microtime(true) > $deadline) {
                                throw new \RuntimeException('Timeout na barreira fiscal.');
                            }
                            usleep(10000);
                        }
                        $record = app(PrepareFiscalReview::class)->prepare($this->tenant, $this->sale->id, $this->user->id, $key);
                        file_put_contents($directory.'/result-'.$worker, json_encode(['id' => $record->id]));
                    } catch (\Throwable $e) {
                        file_put_contents($directory.'/result-'.$worker, json_encode(['error' => $e::class]));
                    } finally {
                        app(TenantConnection::class)->disconnect();
                    }
                    exit(0);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
            }
            $results = [];
            for ($worker = 0; $worker < 2; $worker++) {
                $result = json_decode(file_get_contents($directory.'/result-'.$worker), true, flags: JSON_THROW_ON_ERROR);
                $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
                $results[] = $result['id'];
            }
            $this->assertSame($results[0], $results[1]);
            app(TenantConnection::class)->connect($this->tenant);
            $this->assertSame(1, FiscalPreparation::count());
            $this->assertSame(0, DB::connection('tenant')->table('fiscal_sequences')->count());
            $this->assertSame(0, DB::connection('tenant')->table('fiscal_outbox')->count());
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
