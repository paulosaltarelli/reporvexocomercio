<?php

namespace Tests\Feature;

use App\Fiscal\FiscalTransportException;
use App\Fiscal\Snapshot;
use App\Fiscal\Nfse\DpsVerifier;
use Tests\Support\ArchivedDps;
use App\Models\BusinessProfile;
use App\Models\Customer;
use App\Models\NfseConfiguration;
use App\Models\NfseDocument;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Checkout;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Historic documents are imported from a signed synthetic fixture. */
class NfseTest extends TestCase
{
    private static bool $schemasReady = false;
    private bool $networkGuard = false;
    private Tenant $tenant;
    private User $user;
    private ProductGroup $group;
    private Product $service;
    private Customer $customer;
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
        if (! self::$schemasReady) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            foreach (['a', 'b'] as $slug) {
                app(TenantConnection::class)->connect(new Tenant($this->tenantAttributes($slug)));
                $this->assertTestDatabase('tenant', 'vendra_t_test_'.$slug);
                Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            }
            self::$schemasReady = true;
        }
        $this->clearTestDatabase('central', 'vendra_test_central');
        foreach (['a', 'b'] as $slug) {
            $tenant = Tenant::create($this->tenantAttributes($slug));
            app(TenantConnection::class)->connect($tenant);
            $this->clearTestDatabase('tenant', 'vendra_t_test_'.$slug);
            if ($slug === 'a') { $this->tenant = $tenant; }
        }
        app(TenantConnection::class)->connect($this->tenant);
        Http::preventStrayRequests();
        Http::fake();
        $this->networkGuard = true;
        $this->user = User::create(['name'=>'Admin NFSe', 'email'=>'nfse@example.test', 'password'=>'test-password-123', 'role'=>'admin']);
        BusinessProfile::whereKey(1)->delete();
        BusinessProfile::forceCreate(['id'=>1, 'legal_name'=>'PRESTADOR SINTETICO SEM VALOR FISCAL', 'document'=>'11.222.333/0001-81', 'municipality_code'=>'3550308', 'state'=>'SP', 'tax_regime'=>'simples']);
        $this->group = ProductGroup::create(['name'=>'Serviços de teste', 'margin'=>'20']);
        $this->service = Product::create(['name'=>'SERVICO SINTETICO SEM VALOR FISCAL', 'sku'=>'NFSE-TEST', 'type'=>'service', 'controls_stock'=>false, 'reference_cost'=>'10.0000', 'sale_price'=>'100.01', 'product_group_id'=>$this->group->id]);
        $this->customer = Customer::create(['name'=>'TOMADOR SINTETICO', 'document'=>'52998224725']);
        $this->sale = $this->saleFor($this->service);
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
            if ($this->networkGuard) { Http::assertNothingSent(); }
            app(TenantConnection::class)->disconnect();
            DB::purge('central');
        }
        parent::tearDown();
    }

    private function connect(): void { app(TenantConnection::class)->connect($this->tenant); }
    private function login(?User $user = null): void { $this->withSession(['tenant_id'=>$this->tenant->id,'user_id'=>($user??$this->user)->id,'auth_version'=>0]); }

    private function saleFor(Product $product, array $extra = []): Sale
    {
        return app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'customer_id'=>$this->customer->id,'paid'=>true,'payment_method'=>'cash',
            'items'=>array_merge([['kind'=>'product','id'=>$product->id,'quantity'=>'1','unit_price'=>$product->sale_price]],$extra)],$this->user->id);
    }

    public function test_mixed_work_order_preserves_sales_and_catalog_without_offering_dps(): void
    {
        config(['fiscal.product_invoices_enabled'=>false]);
        $part=Product::create(['name'=>'Peça de teste','sku'=>'OS-PECA','type'=>'product','unit'=>'UN',
            'controls_stock'=>false,'barcode'=>'7891234567890','sale_price'=>'12.34','product_group_id'=>$this->group->id,
            'ncm'=>'12345678','origin'=>'0']);
        $orders=app(\App\Services\Documents::class);
        $order=$orders->create(['type'=>'work_order','request_key'=>(string)Str::uuid(),'items'=>[
            ['kind'=>'product','id'=>$this->service->id,'quantity'=>'1','unit_price'=>$this->service->sale_price],
            ['kind'=>'product','id'=>$part->id,'quantity'=>'1','unit_price'=>$part->sale_price],
        ]],$this->user->id);
        $orders->transition($order->id,'in_progress');
        $orders->transition($order->id,'completed');
        $sale=$orders->invoice($order->id,['paid'=>true,'payment_method'=>'cash'],$this->user->id);
        $this->login();
        $path="/app/a/nfse/sales/{$sale->id}";
        foreach (["/app/a/documents/{$order->id}", "/app/a/sales/{$sale->id}"] as $url) {
            $this->get($url)->assertOk()->assertDontSee($path,false)->assertDontSee('NFC-e')
                ->assertDontSee('/fiscal/profiles',false)->assertDontSee('/fiscal/simulations',false);
        }
        $this->get("/app/a/products/{$part->id}/edit")->assertOk()->assertSee('name="barcode"',false)
            ->assertDontSee('name="ncm"',false)->assertDontSee('name="origin"',false);
        $this->post("/app/a/products/save/{$part->id}",['name'=>$part->name,'sku'=>$part->sku,'unit'=>'UN',
            'barcode'=>'7891234567891','product_group_id'=>$this->group->id,'sale_price'=>'12.34',
            'ncm'=>'87654321','origin'=>'1','active'=>1])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path.'/prepare', ['segregate_work_order'=>true])->assertNotFound();
        $this->connect();
        $this->assertSame('7891234567891',$part->fresh()->barcode);
        $this->assertSame('12345678',$part->fresh()->ncm);
        $this->assertSame('0',(string)$part->fresh()->origin);
        $this->assertSame(0,NfseDocument::count());
        $this->assertSame('112.35',$sale->fresh()->total);
        $this->assertSame(0,DB::connection('tenant')->table('fiscal_documents')->count());
        $this->assertSame(0,DB::connection('tenant')->table('fiscal_outbox')->count());
    }

    private function databaseFacts(): array
    {
        $facts = [];
        foreach (['nfse_configurations', 'nfse_sequences', 'nfse_documents', 'sales', 'sale_items',
            'stock_movements', 'payments', 'financial_entries', 'fiscal_documents', 'fiscal_outbox'] as $table) {
            $facts[$table] = DB::connection('tenant')->table($table)->orderBy('id')->get()->toArray();
        }
        return $facts;
    }

    public function test_no_profile_can_prepare_sign_configure_or_transmit_a_service_document(): void
    {
        $this->login();
        $before = $this->databaseFacts();
        foreach ([false, true] as $goodsEnabled) {
            config(['fiscal.product_invoices_enabled' => $goodsEnabled]);
            $this->get('/app/a/nfse')->assertOk()->assertDontSee('Preparar DPS')
                ->assertDontSee('name="certificate_password"', false);
            foreach (['nfse/config', 'nfse/configuration/draft', "nfse/sales/{$this->sale->id}/prepare",
                'nfse/documents/1/issue', 'nfse/documents/1/consult'] as $path) {
                $response = $this->postJson('/app/a/'.$path, ['request_key' => (string) Str::uuid(),
                    'operation_scope_confirmed' => true, 'competence' => today()->toDateString()]);
                $this->assertContains($response->status(), [404, 405], $path);
            }
        }
        $this->connect();
        $this->assertEquals($before, $this->databaseFacts());
        $this->assertFalse(method_exists(\App\Fiscal\Nfse\PrepareDps::class, 'prepare'));
        $this->assertFalse(method_exists(\App\Fiscal\Nfse\DpsBuilder::class, 'build'));
    }

    public function test_goods_routes_are_still_unavailable_in_v1(): void
    {
        config(['fiscal.product_invoices_enabled' => false]);
        $this->login();
        foreach (['fiscal/profiles', 'fiscal/simulations', 'fiscal/nfe/customers/'.$this->customer->id] as $path) {
            $this->get('/app/a/'.$path)->assertNotFound();
        }
        foreach (['business/fiscal/nfe', "sales/{$this->sale->id}/fiscal/nfe/emit",
            "sales/{$this->sale->id}/fiscal/emit", "sales/{$this->sale->id}/fiscal/simulate"] as $path) {
            $this->post('/app/a/'.$path)->assertNotFound();
        }
    }

    public function test_historical_reads_preserve_original_bytes_numbering_and_commerce_without_a_private_key(): void
    {
        $doc = ArchivedDps::import($this->tenant, $this->sale, $this->user->id);
        $xml = file_get_contents(__DIR__.'/../Fixtures/nfse/archived.xml');
        $before = $this->databaseFacts();
        $this->assertNull(NfseConfiguration::sole()->secrets);
        $this->assertStringNotContainsString('<DPS', $before['nfse_documents'][0]->signed_xml);
        $this->login();
        $base = '/app/a/nfse/documents/'.$doc->id;
        $this->get('/app/a/nfse')->assertOk()->assertSee($base, false);
        $this->get('/app/a/nfse/sales/'.$this->sale->id)->assertRedirect($base);
        $this->get($base)->assertOk()->assertSee($doc->dps_id)->assertSee($base.'/xml', false);
        $result = $this->get($base.'/xml')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($xml, $result->getContent());
        $this->assertSame(hash('sha256', $xml), $doc->xml_sha256);
        $this->connect();
        $this->assertEquals($before, $this->databaseFacts());
    }

    public function test_historical_documents_cannot_be_written_or_removed_by_the_models(): void
    {
        $doc = ArchivedDps::import($this->tenant, $this->sale, $this->user->id);
        $config = NfseConfiguration::sole();
        $before = $this->databaseFacts();
        foreach ([fn () => $doc->update(['status'=>'authorized']), fn () => $doc->delete(),
            fn () => $config->update(['enabled'=>false]), fn () => $config->delete()] as $change) {
            try {
                $change();
                $this->fail('O registro histórico aceitou uma alteração.');
            } catch (\LogicException) {
                $this->assertEquals($before, $this->databaseFacts());
            }
        }
    }

    public function test_archived_xml_with_a_valid_signature_must_match_the_frozen_snapshot_and_certificate(): void
    {
        $doc = ArchivedDps::import($this->tenant, $this->sale, $this->user->id);
        $validator = app(DpsVerifier::class);
        $validator->validate($doc->signed_xml, $doc->snapshot);
        foreach (['total' => '8.01', 'certificate_fingerprint' => str_repeat('0', 64), 'environment' => 'production'] as $key=>$value) {
            try {
                $validator->validate($doc->signed_xml, array_replace($doc->snapshot, [$key=>$value]));
                $this->fail('Um XML assinado não corresponde ao registro histórico.');
            } catch (FiscalTransportException $error) {
                $this->assertStringStartsWith('nfse_', $error->getMessage());
            }
        }
        $tampered = str_replace('>8.00<', '>8.01<', $doc->signed_xml);
        $this->assertNotSame($doc->signed_xml, $tampered);
        DB::connection('tenant')->table('nfse_documents')->where('id', $doc->id)->update([
            'signed_xml'=>Crypt::encryptString($tampered), 'xml_sha256'=>hash('sha256', $tampered),
        ]);
        $this->login();
        $this->get('/app/a/nfse/documents/'.$doc->id.'/xml')->assertStatus(409);
    }

    public function test_archive_enforces_operator_permissions_tenant_isolation_and_snapshot_binding(): void
    {
        $doc = ArchivedDps::import($this->tenant, $this->sale, $this->user->id);
        $operator = User::create(['name'=>'Operador','email'=>'nfse-operator@example.test','password'=>'test-password-123','role'=>'operator']);
        $this->login($operator);
        $this->get('/app/a/nfse')->assertForbidden();
        $this->get('/app/a/nfse/documents/'.$doc->id.'/xml')->assertForbidden();
        $this->connect();
        $snapshot = array_replace($doc->snapshot, ['tenant_id' => $this->tenant->id + 100]);
        DB::connection('tenant')->table('nfse_documents')->where('id', $doc->id)->update([
            'snapshot'=>json_encode($snapshot, JSON_THROW_ON_ERROR), 'snapshot_sha256'=>Snapshot::hash($snapshot),
        ]);
        $this->login();
        $this->get('/app/a/nfse/documents/'.$doc->id)->assertStatus(409);
        $other = Tenant::where('slug','b')->sole();
        app(TenantConnection::class)->connect($other);
        $owner = User::create(['name'=>'Admin B','email'=>'nfse-b@example.test','password'=>'test-password-123','role'=>'admin']);
        $this->withSession(['tenant_id'=>$other->id,'user_id'=>$owner->id,'auth_version'=>0]);
        $this->get('/app/b/nfse/documents/'.$doc->id.'/xml')->assertNotFound();
    }
}
