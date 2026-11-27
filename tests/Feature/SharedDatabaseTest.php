<?php

namespace Tests\Feature;

use App\Fiscal\FiscalTransportException;
use App\Fiscal\Nfse\Access;
use App\Fiscal\PrivateFiscalFiles;
use App\Fiscal\ProcessFiscalOutbox;
use App\Fiscal\ProductInvoiceGate;
use App\Fiscal\QueueNfce;
use App\Fiscal\QueueNfe;
use App\Fiscal\SefazTransport;
use App\Fiscal\SimulationFiscal;
use App\Fiscal\Snapshot;
use App\Models\BusinessProfile;
use App\Models\CompanyOperation;
use App\Models\Customer;
use App\Models\DemoRun;
use App\Models\Document;
use App\Models\FinancialEntry;
use App\Models\FiscalDocument;
use App\Models\FiscalIssuer;
use App\Models\FiscalSimulation;
use App\Models\NfseDocument;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\PlatformAudit;
use App\Services\CashService;
use App\Services\Checkout;
use App\Services\CompanyOperations;
use App\Services\Documents;
use App\Services\FinancialService;
use App\Services\Inventory;
use App\Services\PurchaseService;
use App\Services\TenantConnection;
use App\Tenancy\MysqlTenantStorageProvisioner;
use App\Tenancy\SchemaManifest;
use App\Tenancy\SharedSchemaBuilder;
use App\Tenancy\SharedTenantImporter;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PDO;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Actual MySQL views and runtime users; no operational/control-plane credentials are loaded. */
class SharedDatabaseTest extends TestCase
{
    private static ?PDO $admin = null;

    private static ?string $database = null;

    private static array $manifest = [];

    private static array $schema = [];

    private static array $sourceData = [];

    private static bool $centralReady = false;

    private Tenant $a;

    private Tenant $b;

    private bool $networkGuard = false;

    private bool $soapAttempted = false;

    private const SOURCE = 'vendra_t_test_a';

    private const OWNER_PASSWORD = 'Owner-test-pass-1234';

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure os bancos MySQL dedicados aos testes.');
        }
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            throw new \RuntimeException('Shared QA requires root CLI with a disposable schema.');
        }
        config(['database.default' => 'central', 'database.connections.central' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'vendra_test_central', 'username' => 'vendra_test', 'password' => env('VENDRA_TEST_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
        DB::purge('central');
        $central = DB::connection('central');
        if ($central->getDatabaseName() !== 'vendra_test_central' || $central->getConfig('username') !== 'vendra_test' || $central->selectOne('SELECT DATABASE() AS db')->db !== 'vendra_test_central') {
            throw new \RuntimeException('Non-test central database refused before DDL.');
        }
        if (! self::$centralReady) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            self::$centralReady = true;
        }
        $central->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($central->select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if ($table === 'migrations') {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                    throw new \RuntimeException('Unexpected central test table.');
                }
                $central->delete('DELETE FROM `'.$table.'`');
            }
        } finally {
            $central->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        if (self::$admin === null) {
            self::$admin = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
            if (! str_starts_with((string) self::$admin->query('SELECT CURRENT_USER()')->fetchColumn(), 'root@') || ! in_array(self::SOURCE, ['vendra_t_test_a', 'vendra_t_test_b'], true)) {
                throw new \RuntimeException('Privileged source guard refused.');
            }
            self::$manifest = SchemaManifest::capture(self::$admin, self::SOURCE);
            self::$sourceData = $this->sourceDataHashes();
            self::$database = 'vendra_shared_qa_'.bin2hex(random_bytes(4));
            if (! preg_match('/^vendra_shared_qa_[a-f0-9]{8}$/D', self::$database)) {
                throw new \RuntimeException('Non-disposable target refused.');
            }
            self::$schema = (new SharedSchemaBuilder(self::$admin))->build(self::SOURCE, self::$database, self::$manifest);
        }
        config(['tenancy.shared.enabled' => true, 'tenancy.shared.database' => self::$database, 'tenancy.shared.host' => '127.0.0.1', 'tenancy.shared.port' => 3306, 'tenancy.shared.manifest_hash' => SharedSchemaBuilder::manifestHash(self::$manifest)]);
        $this->a = $this->newCompany('shared-a');
        $this->b = $this->newCompany('shared-b');
        Storage::fake('local');
        Storage::fake('fiscal');
        Http::preventStrayRequests();
        Http::fake();
        $this->networkGuard = true;
        $this->app->bind(SefazTransport::class, function () {
            $this->soapAttempted = true;
            throw new \LogicException('External fiscal transport forbidden in shared QA.');
        });
        $this->connect($this->a);
    }

    private function newCompany(string $slug): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => 'Empresa '.$slug, 'active' => false, 'storage_mode' => 'shared', 'is_demonstration' => false, 'fiscal_demonstration' => true, 'db_host' => '127.0.0.1', 'db_name' => self::$database, 'db_username' => 'pending_'.bin2hex(random_bytes(4)), 'db_password' => bin2hex(random_bytes(24))]);
        $tenant->update(['db_username' => 'vxs_'.str_pad(dechex($tenant->id), 16, '0', STR_PAD_LEFT).'_'.bin2hex(random_bytes(4))]);
        (new MysqlTenantStorageProvisioner(self::$admin, self::$manifest))->provisionNew($tenant, ['name' => 'Responsável '.$slug, 'email' => 'owner@example.test', 'must_change_password' => true], self::OWNER_PASSWORD);
        $tenant->update(['active' => true, 'provisioned_at' => now(), 'platform_version' => 1]);

        return $tenant->fresh();
    }

    private function connect(Tenant $tenant): User
    {
        app(TenantConnection::class)->connect($tenant);
        $this->assertTrue(app(TenantConnection::class)->matches($tenant));

        return User::where('email', 'owner@example.test')->firstOrFail();
    }

    private function catalog(Tenant $tenant): array
    {
        $owner = $this->connect($tenant);
        $configuration = array_replace(CompanyOperations::DEFAULTS, ['version' => CompanyOperation::findOrFail(1)->version]);
        app(CompanyOperations::class)->save($configuration, $owner->id);
        $group = ProductGroup::create(['name' => 'Grupo compartilhado', 'margin' => '40', 'discount_enabled' => true, 'max_discount_percent' => '20', 'commission_percent' => '10']);
        $product = Product::create(['name' => 'Produto '.$tenant->slug, 'sku' => 'SHARED-SKU', 'product_group_id' => $group->id, 'sale_price' => '100', 'unit' => 'UN']);
        $service = Product::create(['name' => 'Serviço '.$tenant->slug, 'sku' => 'SHARED-SERVICE', 'product_group_id' => $group->id, 'type' => 'service', 'controls_stock' => false, 'reference_cost' => '5', 'sale_price' => '50', 'unit' => 'H']);
        $customer = Customer::create(['name' => 'Cliente '.$tenant->slug, 'email' => 'customer@example.test', 'document' => '52998224725']);
        $supplier = Supplier::create(['name' => 'Fornecedor '.$tenant->slug]);

        return compact('owner', 'group', 'product', 'service', 'customer', 'supplier');
    }

    private function sessionFor(Tenant $tenant): User
    {
        $owner = $this->connect($tenant);
        $owner->update(['must_change_password' => false]);
        $this->withSession(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'auth_version' => $owner->auth_version]);

        return $owner;
    }

    private function sourceDataHashes(string $source = self::SOURCE): array
    {
        if (! in_array($source, ['vendra_t_test_a', 'vendra_t_test_b'], true)) {
            throw new \RuntimeException('Non-test source hash refused.');
        }
        $result = [];
        foreach (self::$manifest['tables'] as $table) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $table)) {
                throw new \RuntimeException('Unexpected source table.');
            }
            $rows = self::$admin->query('SELECT * FROM `'.$source.'`.`'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $hashes = array_map(fn ($row) => Snapshot::hash($row), $rows);
            sort($hashes);
            $result[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
        }

        return $result;
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                if ($this->networkGuard) {
                    Http::assertNothingSent();
                    $this->assertFalse($this->soapAttempted);
                }
                app(TenantConnection::class)->disconnect();
                DB::purge('central');
                if (self::$schema) {
                    $this->assertSame(self::$sourceData, $this->sourceDataHashes(), 'The isolated template source must remain byte-for-byte logically unchanged.');
                    SchemaManifest::assertTemplate(self::$admin, self::SOURCE, self::$manifest);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$admin && self::$schema && preg_match('/^vendra_shared_qa_[a-f0-9]{8}$/D', (string) self::$database)) {
            $builder = new SharedSchemaBuilder(self::$admin);
            $logins = self::$admin->query('SELECT login FROM `'.self::$database.'`._vendra_principals')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($logins as $login) {
                $builder->dropPrincipal(self::$database, $login);
            }
            self::$admin->exec('DROP DATABASE `'.self::$database.'`');
            $definer = self::$schema['definer'] ?? null;
            if (is_string($definer) && preg_match('/^vxd_[a-f0-9]{24}$/D', $definer)) {
                self::$admin->exec('DROP USER IF EXISTS '.self::$admin->quote($definer)."@'localhost'");
            }
        }
        parent::tearDownAfterClass();
    }

    public function test_shared_sql_and_orm_isolate_same_schema_singletons_raw_writes_uniques_and_foreign_keys(): void
    {
        foreach ([$this->a, $this->b] as $tenant) {
            $this->connect($tenant);
            $this->assertSame(self::$database, DB::connection('tenant')->getDatabaseName());
            $this->assertSame(0, Product::count());
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, Customer::count());
            $this->assertSame(0, DemoRun::count());
            $this->assertSame(1, User::count());
            $this->assertSame(1, BusinessProfile::findOrFail(1)->id);
            $this->assertSame(1, CompanyOperation::findOrFail(1)->id);
            $this->assertSame($tenant->id, (int) DB::connection('tenant')->selectOne('SELECT tenant_id FROM __vendra_identity')->tenant_id);
        }
        $a = $this->catalog($this->a);
        $b = $this->catalog($this->b);
        $this->assertNotSame($a['product']->id, $b['product']->id);
        $this->connect($this->a);
        $this->assertSame([$a['product']->id, $a['service']->id], Product::orderBy('id')->pluck('id')->all());
        $this->assertSame(2, (int) DB::connection('tenant')->selectOne('SELECT COUNT(*) AS n FROM products')->n);
        $this->assertSame('Grupo compartilhado', DB::connection('tenant')->selectOne('SELECT g.name FROM products p JOIN product_groups g ON g.id=p.product_group_id WHERE p.id=?', [$a['product']->id])->name);
        try {
            DB::connection('tenant')->select('SELECT * FROM _p_products');
            $this->fail('Runtime must not read physical storage.');
        } catch (QueryException $e) {
            $this->assertSame(1142, (int) $e->errorInfo[1]);
        }
        try {
            DB::connection('tenant')->table('products')->insert(['name' => 'Foreign parent', 'sku' => 'BAD-FK', 'product_group_id' => $b['group']->id, 'sale_price' => '1']);
            $this->fail('Cross-namespace FK must fail.');
        } catch (QueryException $e) {
            $this->assertContains((int) $e->errorInfo[1], [1216, 1452]);
        }
        DB::connection('tenant')->beginTransaction();
        try {
            DB::connection('tenant')->update("UPDATE products SET name='Only A changed'");
            $this->connect($this->b);
            $this->assertSame('Produto shared-b', Product::findOrFail($b['product']->id)->name);
        } finally {
            $this->connect($this->a); /* reconnect intentionally rolls back an abandoned transaction */
        }
        $this->assertSame('Produto shared-a', Product::findOrFail($a['product']->id)->name);
        $invalid = $this->a->replicate();
        $invalid->id = $this->a->id;
        $invalid->db_username = $this->b->db_username;
        $invalid->db_password = $this->b->db_password;
        try {
            app(TenantConnection::class)->connect($invalid);
            $this->fail('Configured tenant cannot borrow another runtime identity.');
        } catch (\RuntimeException) {
            $this->assertNull(config('database.connections.tenant'));
        }
    }

    public function test_shared_erp_weighted_cost_purchases_installments_payments_commission_and_daily_cash_are_isolated(): void
    {
        $b = $this->catalog($this->b);
        $a = $this->catalog($this->a);
        $purchase = app(PurchaseService::class)->receive(['request_key' => (string) Str::uuid(), 'supplier_id' => $a['supplier']->id, 'purchased_on' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(), 'payment_method' => 'invoice', 'paid' => false, 'items' => [
            ['product_id' => $a['product']->id, 'quantity' => '2.5', 'unit_cost' => '40'], ['product_id' => $a['product']->id, 'quantity' => '2.5', 'unit_cost' => '60'],
        ]], $a['owner']->id);
        $this->assertSame('250.00', $purchase->total);
        $this->assertSame('50.0000', $a['product']->fresh()->average_cost);
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'customer_id' => $a['customer']->id, 'payment_method' => 'invoice', 'paid' => false, 'installments' => 3, 'due_date' => now()->addDay()->toDateString(), 'items' => [['kind' => 'product', 'id' => $a['product']->id, 'quantity' => '1.5', 'unit_price' => '100', 'discount_percent' => '10']]], $a['owner']->id);
        $this->assertSame('135.00', $sale->total);
        $this->assertSame('13.50', $sale->commission_total);
        $this->assertSame('75.0000', $sale->cost_total);
        $this->assertSame('3.500', $a['product']->fresh()->quantity);
        $entries = $sale->entries()->orderBy('installment_no')->get();
        $this->assertSame(['45.00', '45.00', '45.00'], $entries->pluck('amount')->all());
        $financial = app(FinancialService::class);
        $financial->pay($entries[0]->id, '45.00', now()->toDateString(), 'cash', $a['owner']->id, (string) Str::uuid());
        $financial->pay($entries[1]->id, '20.00', now()->toDateString(), 'pix', $a['owner']->id, (string) Str::uuid());
        $payable = $purchase->entries()->firstOrFail();
        $financial->pay($payable->id, '100.00', now()->toDateString(), 'cash', $a['owner']->id, (string) Str::uuid());
        $this->assertFalse($sale->fresh()->paid);
        $this->sessionFor($this->a);
        $this->get('/app/shared-a/commissions?from='.now()->toDateString().'&to='.now()->toDateString())->assertOk()->assertViewHas('totals', fn ($totals) => bccomp((string) $totals['eligible'], '6.50', 2) === 0);
        $this->connect($this->a);
        $summary = app(CashService::class)->summary(now()->toDateString(), '200.00');
        $this->assertSame('145.00', $summary['expected_balance']);
        $closure = app(CashService::class)->close(['request_key' => (string) Str::uuid(), 'date' => now()->toDateString(), 'opening_balance' => '200.00', 'counted_balance' => '145.00', 'notes' => 'QA isolated closing'], $a['owner']->id);
        $this->assertSame('0.00', $closure->difference);
        $this->connect($this->b);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame('0.000', $b['product']->fresh()->quantity);
        $this->assertFalse(app(CashService::class)->summary(now()->toDateString(), '0.00')['is_closed']);
    }

    public function test_shared_quotes_and_work_orders_move_stock_only_at_invoice_and_keep_credited_owner(): void
    {
        $a = $this->catalog($this->a);
        app(Inventory::class)->move($a['product']->id, 'in', '5', '60', 'Opening QA', $a['owner']->id, (string) Str::uuid());
        $documents = app(Documents::class);
        $key = (string) Str::uuid();
        $quote = $documents->create(['type' => 'quote', 'request_key' => $key, 'customer_id' => $a['customer']->id, 'items' => [['kind' => 'product', 'id' => $a['product']->id, 'quantity' => '1', 'unit_price' => '100']]], $a['owner']->id);
        $os = $documents->create(['type' => 'work_order', 'request_key' => (string) Str::uuid(), 'asset' => 'Notebook QA', 'notes' => 'Diagnóstico preservado', 'items' => [['kind' => 'product', 'id' => $a['service']->id, 'quantity' => '2', 'unit_price' => '50']]], $a['owner']->id);
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame('5.000', $a['product']->fresh()->quantity);
        $documents->transition($quote->id, 'approved');
        foreach (['approved', 'in_progress', 'completed'] as $status) {
            $documents->transition($os->id, $status);
        }
        $payment = ['payment_method' => 'invoice', 'paid' => false, 'due_date' => now()->addMonth()->toDateString()];
        $sale = $documents->invoice($quote->id, $payment, $a['owner']->id);
        $serviceSale = $documents->invoice($os->id, $payment, $a['owner']->id);
        $this->assertSame($sale->id, $documents->invoice($quote->id, $payment, $a['owner']->id)->id);
        $this->assertSame($quote->seller_name, $sale->seller_name);
        $this->assertSame('4.000', $a['product']->fresh()->quantity);
        $this->assertSame(2, StockMovement::count());
        $this->assertSame(2, Sale::count());
        $this->assertSame('10.0000', $serviceSale->cost_total);
        $b = $this->catalog($this->b);
        $copy = $documents->create(['type' => 'quote', 'request_key' => $key, 'items' => [['kind' => 'product', 'id' => $b['service']->id, 'quantity' => '1', 'unit_price' => '50']]], $b['owner']->id);
        $this->assertNotSame($quote->id, $copy->id);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_shared_fiscal_simulation_leaves_service_tax_archive_unchanged_and_rejects_wrong_tenant_context(): void
    {
        config(['fiscal.product_invoices_enabled' => true]);
        $b = $this->catalog($this->b);
        $a = $this->catalog($this->a);
        BusinessProfile::findOrFail(1)->update(['document' => '11222333000181', 'tax_regime' => 'simples', 'municipality_code' => '3550308', 'street' => 'Rua QA', 'number' => '1', 'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '01153000']);
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'paid' => true, 'payment_method' => 'cash', 'items' => [['kind' => 'product', 'id' => $a['service']->id, 'quantity' => '1', 'unit_price' => '50']]], $a['owner']->id);
        $simulation = app(SimulationFiscal::class)->create($this->a, $sale->id, $a['owner']->id, (string) Str::uuid(), 'uncertain');
        $this->assertSame('uncertain', $simulation->state);
        $this->assertSame('accepted', app(SimulationFiscal::class)->consult($this->a, $simulation->id, $a['owner']->id, (string) Str::uuid())->state);
        $this->assertSame('cancelled', app(SimulationFiscal::class)->cancel($this->a, $simulation->id, $a['owner']->id, 'Cancelamento interno de QA', (string) Str::uuid())->state);
        $this->assertSame(0, NfseDocument::count());
        $this->assertSame(0, DB::connection('tenant')->table('nfse_sequences')->count());
        $this->assertSame(0, FiscalDocument::count());
        $this->assertSame(0, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->connect($this->b);
        $this->assertFalse(app(TenantConnection::class)->matches($this->a));
        foreach ([
            fn () => app(SimulationFiscal::class)->create($this->a, $sale->id, $b['owner']->id, (string) Str::uuid()),
            fn () => Access::admin($this->a, $b['owner']->id),
            fn () => app(QueueNfce::class)->enqueue($this->a, $sale->id, $b['owner']->id, []),
            fn () => app(QueueNfe::class)->enqueue($this->a, $sale->id, $b['owner']->id, []),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Wrong shared tenant context must be rejected before reading a sale.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame(0, FiscalSimulation::count());
        $this->assertSame(0, FiscalDocument::count());
    }

    public function test_shared_commission_closure_and_named_payment_remain_tenant_scoped(): void
    {
        $shop=$this->catalog($this->a);$catalog=app(\App\Services\PaymentMethods::class);
        $catalog->save(['version'=>CompanyOperation::find(1)->version,'label'=>'PIX PRIVATE STORE A','type'=>'pix','active'=>1],$shop['owner']->id);$method=array_key_last($catalog->all());
        $sale=app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'payment_method'=>'pix','payment_option'=>$method,'paid'=>true,'items'=>[['kind'=>'product','id'=>$shop['service']->id,'quantity'=>'1','unit_price'=>'50']]],$shop['owner']->id);
        $service=app(\App\Services\CommissionSettlements::class);$preview=$service->preview(['from'=>now()->startOfMonth()->toDateString(),'to'=>now()->toDateString(),'salesperson_id'=>$sale->salesperson_id]);
        $closure=$service->close(array_intersect_key($preview,array_flip(['from','to','salesperson_id','preview_hash']))+['request_key'=>(string)Str::uuid(),'due_date'=>now()->toDateString(),'reviewed'=>1],$shop['owner']->id);$this->assertSame('5.00',$closure->after['total']);
        $this->connect($this->b);$this->assertSame(0,$service->records()->count());$this->assertArrayNotHasKey($method,$catalog->all());
        try{$service->record($closure->id);$this->fail('Another tenant closure exposed.');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException){$this->assertSame(0,FinancialEntry::where('category','Comissões de vendas')->count());}
        $this->sessionFor($this->b);$this->get('/app/shared-b/my-commissions/'.$closure->id)->assertForbidden();
        $this->sessionFor($this->a);$this->get('/app/shared-a/my-commissions/'.$closure->id)->assertOk()->assertSee('5,00');
    }

    public function test_shared_split_payment_plan_cash_opening_and_os_are_tenant_scoped(): void
    {
        $shop=$this->catalog($this->a);$cash=app(\App\Services\CashService::class);
        $cash->open(['date'=>now()->toDateString(),'opening_balance'=>'20'],$shop['owner']->id);
        $sale=app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'payments'=>[['payment_option'=>'cash','amount'=>'20','paid'=>1],['payment_option'=>'pix','amount'=>'30','paid'=>1]],'items'=>[['kind'=>'product','id'=>$shop['service']->id,'quantity'=>'1','unit_price'=>'50']]],$shop['owner']->id);
        $this->assertTrue($sale->paid);$this->assertSame('40.00',$cash->summary(now()->toDateString())['expected_balance']);$this->assertCount(2,app(\App\Services\SalePayments::class)->rows($sale->id));
        $this->connect($this->b);$this->assertSame([],app(\App\Services\SalePayments::class)->rows($sale->id));$this->assertNull($cash->opening(now()->toDateString()));$this->assertSame(0,FinancialEntry::count());$this->assertSame(0,Payment::count());
        $this->sessionFor($this->b);$this->get('/app/shared-b/sales/'.$sale->id)->assertNotFound();
    }

    public function test_shared_card_brands_fees_and_payables_do_not_leak_between_stores(): void
    {
        $shop=$this->catalog($this->a);$fees=app(\App\Services\CardFees::class);
        $fees->save(['version'=>CompanyOperation::find(1)->version,'name'=>'Contrato privado A','type'=>'credit','policy'=>'pass','active'=>1,'rates'=>[1=>'3']],$shop['owner']->id);$brand=array_key_last($fees->all());
        $sale=app(Checkout::class)->complete(['request_key'=>(string)Str::uuid(),'payments'=>[['payment_option'=>'credit','amount'=>'50','paid'=>1,'card_brand'=>$brand,'card_installments'=>1,'fee_policy'=>'pass','rate_seen'=>'3','fee_reviewed'=>1,'charged_seen'=>'51.55']],'items'=>[['kind'=>'product','id'=>$shop['service']->id,'quantity'=>'1','unit_price'=>'50']]],$shop['owner']->id);
        $this->assertSame('51.55',$sale->total);$this->assertSame('1.55',FinancialEntry::where('category','Taxas de cartão')->firstOrFail()->amount);
        $this->connect($this->b);$this->assertSame([],$fees->all());$this->assertSame(0,FinancialEntry::where('category','Taxas de cartão')->count());$this->assertSame([],app(\App\Services\SalePayments::class)->rows($sale->id));
        try{$fees->quote('50','credit',['card_brand'=>$brand,'card_installments'=>1,'fee_policy'=>'pass','rate_seen'=>'3','fee_reviewed'=>1,'charged_seen'=>'51.55']);$this->fail('Foreign card contract accepted.');}catch(\Illuminate\Validation\ValidationException){$this->assertSame(0,Payment::count());}
    }

    public function test_shared_quick_customer_item_and_idempotency_are_scoped_to_store(): void
    {
        $a=$this->catalog($this->a);$quick=app(\App\Services\QuickCreate::class);$data=['request_key'=>(string)Str::uuid(),'name'=>'Cliente rápido privado','document'=>'11144477735'];
        $customer=$quick->create('customers',$data,$a['owner']->id);$item=$quick->create('services',['request_key'=>(string)Str::uuid(),'name'=>'Serviço rápido privado','product_group_id'=>$a['group']->id,'unit'=>'UN','sale_price'=>'15','controls_stock'=>0],$a['owner']->id);
        $owner=$this->connect($this->b);$this->assertNull(Customer::find($customer['id']));$this->assertNull(Product::find($item['id']));$b=$quick->create('customers',$data,$owner->id);$this->assertNotSame($customer['id'],$b['id']);$this->assertSame(1,Customer::where('name','Cliente rápido privado')->count());
        $this->assertSame(0,Product::where('name','Serviço rápido privado')->count());
    }

    public function test_shared_installer_acceptance_binds_the_account_only_in_the_inviting_company(): void
    {
        $admin = \App\Platform\PlatformAdmin::create(['name' => 'Central de teste', 'email' => 'central@example.test',
            'password' => 'senha-local-central-123', 'active' => true]);
        $email = 'implantacao@example.test';
        $token = bin2hex(random_bytes(32));
        $grant = \App\Platform\InstallerInvitation::create(['tenant_id' => $this->a->id, 'issued_by' => $admin->id,
            'name' => 'Implantação compartilhada', 'email' => $email, 'status' => 'pending', 'delivery_status' => 'sent',
            'token_digest' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $payload = ['email' => $email, 'token' => $token, 'password' => 'Senha privada escolhida QA', 'password_confirmation' => 'Senha privada escolhida QA'];
        $this->postJson('/app/shared-b/convite-implantacao', $payload)->assertUnprocessable();
        $this->post('/app/shared-a/convite-implantacao', $payload)->assertRedirect('/app/shared-a/setup')->assertSessionHasNoErrors();
        $this->get('/app/shared-a/setup')->assertOk();
        $this->post('/app/shared-a/logout')->assertRedirect('/app/shared-a/login')->assertSessionMissing('user_id');
        $typedEmail = '  '.strtoupper($email).'  ';
        $this->from('/app/shared-a/login')->post('/app/shared-a/login', ['email' => $typedEmail, 'password' => 'senha-incorreta'])
            ->assertRedirect('/app/shared-a/login')->assertSessionHasErrors('email')->assertSessionMissing('user_id');
        $this->from('/app/shared-b/login')->post('/app/shared-b/login', ['email' => $typedEmail, 'password' => $payload['password']])
            ->assertSessionHasErrors('email')->assertSessionMissing('user_id');
        $this->post('/app/shared-a/login', ['email' => $typedEmail, 'password' => $payload['password']])
            ->assertRedirect('/app/shared-a/setup')->assertSessionHasNoErrors()->assertSessionHas('user_id', $grant->fresh()->user_id);
        $this->get('/app/shared-a/setup')->assertOk();
        $this->get('/app/shared-a/desk')->assertForbidden();
        $this->connect($this->a);
        $this->assertSame(User::where('email', $email)->sole()->id, (int) $grant->fresh()->user_id);
        $this->get('/app/shared-b/setup')->assertRedirect('/app/shared-b/login');
        $this->connect($this->b);
        $this->assertFalse(User::where('email', $email)->exists());
        $grant->fresh()->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->post('/app/shared-a/login', ['email' => $typedEmail, 'password' => $payload['password']])
            ->assertSessionHasErrors('email')->assertSessionMissing('user_id');
    }

    public function test_nfce_pilot_follows_resolved_shared_identity_and_filters_existing_jobs(): void
    {
        config(['fiscal.product_invoices_enabled' => false, 'fiscal.nfce_homologation_tenant_ids' => (string) $this->a->id]);
        $jobs = [];
        foreach ([$this->a, $this->b] as $tenant) {
            $catalog = $this->catalog($tenant);
            $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'paid' => true,
                'items' => [['kind' => 'product', 'id' => $catalog['service']->id, 'quantity' => '1', 'unit_price' => '50']]], $catalog['owner']->id);
            $issuer = FiscalIssuer::create(['document' => '11222333000181', 'environment' => 'homologation', 'provider' => 'sefaz_direct']);
            $snapshot = ['tenant_id' => $tenant->id];
            $document = FiscalDocument::create(['issuer_id' => $issuer->id, 'sale_id' => $sale->id, 'reference' => (string) Str::uuid(),
                'model' => 65, 'series' => 1, 'number' => 1, 'state' => 'pending', 'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot)]);
            $jobs[$tenant->id] = DB::connection('tenant')->table('fiscal_outbox')->insertGetId(['document_id' => $document->id,
                'tenant_id' => $tenant->id, 'operation_id' => (string) Str::uuid(), 'operation' => 'issue', 'available_at' => now()]);
        }

        $worker = app(ProcessFiscalOutbox::class);
        $this->assertSame($this->a->db_name, $this->b->db_name);
        $this->assertFalse(ProductInvoiceGate::enabled(65));
        $worker->run($this->b->id, $jobs[$this->b->id]);
        $this->assertSame(0, DB::connection('tenant')->table('fiscal_outbox')->where('id', $jobs[$this->b->id])->value('attempts'));
        $this->assertSame(0, FiscalDocument::accessible()->count());
        config(['tenancy.resolved_tenant_id' => $this->a->id]);
        $this->assertFalse(ProductInvoiceGate::enabled(), 'The connection still belongs to B.');
        $this->connect($this->a);
        $this->assertTrue(ProductInvoiceGate::enabled());
        $this->assertFalse(ProductInvoiceGate::enabled(55));
        $this->assertSame([$jobs[$this->a->id]], $worker->pendingJobs($this->a)->pluck('id')->all());
        app(TenantConnection::class)->disconnect();
        $this->assertFalse(ProductInvoiceGate::enabled());
    }

    public function test_shared_first_login_setup_csrf_logo_private_files_and_job_context_do_not_leak_between_companies(): void
    {
        config(['fiscal.product_invoices_enabled' => true]);
        $owner = $this->connect($this->a);
        $this->assertTrue($owner->must_change_password);
        $this->post('/app/shared-a/login', ['email' => 'owner@example.test', 'password' => self::OWNER_PASSWORD])->assertRedirect('/app/shared-a/setup');
        $this->get('/app/shared-a/setup')->assertRedirect('/app/shared-a/account/password');
        $this->post('/app/shared-a/account/password', ['current_password' => self::OWNER_PASSWORD, 'password' => 'Changed-owner-pass-1234', 'password_confirmation' => 'Changed-owner-pass-1234'])->assertRedirect('/app/shared-a/setup');
        $this->get('/app/shared-a')->assertRedirect('/app/shared-a/setup');
        $this->get('/app/shared-a/setup')->assertOk()->assertSee('Salvar empresa e continuar');
        $this->post('/app/shared-a/business', ['legal_name' => 'Empresa A com logo', 'logo' => UploadedFile::fake()->image('logo.png', 96, 96)])->assertRedirect()->assertSessionHasNoErrors();
        $this->connect($this->a);
        $path = BusinessProfile::findOrFail(1)->logo_path;
        $this->assertStringStartsWith('tenants/'.$this->a->id.'/branding/', $path);
        Storage::disk('local')->assertExists($path);
        $this->get('/app/shared-a/business/logo')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->post('/app/shared-b/login', ['email' => 'owner@example.test', 'password' => self::OWNER_PASSWORD])->assertRedirect('/app/shared-b/setup');
        $this->sessionFor($this->b);
        $this->get('/app/shared-b/business/logo')->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->post('/app/shared-b/business', ['legal_name' => 'Empresa B', 'logo_path' => $path])->assertRedirect()->assertSessionHasNoErrors();
        $this->connect($this->b);
        $this->assertNull(BusinessProfile::findOrFail(1)->logo_path);
        Storage::disk('local')->assertExists($path);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $owner->id, 'auth_version' => 1])->get('/app/shared-b/setup')->assertRedirect('/app/shared-b/login');
        $this->sessionFor($this->a);
        $environment = $this->app->environment();
        $this->app->instance('env', 'local');
        try {
            $this->post('/app/shared-a/business', ['legal_name' => 'CSRF rejected'])->assertStatus(419);
        } finally {
            $this->app->instance('env', $environment);
        }
        $a = $this->catalog($this->a);
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'paid' => false, 'due_date' => now()->addDay()->toDateString(), 'payment_method' => 'invoice', 'items' => [['kind' => 'product', 'id' => $a['service']->id, 'quantity' => '1', 'unit_price' => '50']]], $a['owner']->id);
        $issuer = FiscalIssuer::create(['document' => '11222333000181', 'environment' => 'homologation', 'provider' => 'sefaz_direct', 'enabled' => false]);
        $snapshot = ['tenant_id' => $this->a->id, 'model' => 65, 'configuration' => []];
        $document = FiscalDocument::create(['issuer_id' => $issuer->id, 'sale_id' => $sale->id, 'reference' => (string) Str::uuid(), 'model' => 65, 'series' => 1, 'number' => 1, 'state' => 'prepared', 'snapshot' => $snapshot, 'snapshot_sha256' => Snapshot::hash($snapshot), 'access_key' => str_repeat('1', 20).'65'.str_repeat('1', 22)]);
        app(PrivateFiscalFiles::class)->put($this->a->id, $document->id, 'qa_internal', 'INTERNAL QA BYTES — NOT A FISCAL DOCUMENT');
        $this->assertSame('INTERNAL QA BYTES — NOT A FISCAL DOCUMENT', app(PrivateFiscalFiles::class)->get($this->a->id, $document->id, 'qa_internal'));
        $jobId = DB::connection('tenant')->table('fiscal_outbox')->insertGetId(['document_id' => $document->id, 'tenant_id' => $this->a->id, 'operation_id' => (string) Str::uuid(), 'operation' => 'issue', 'attempts' => 0, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(ProcessFiscalOutbox::class)->run($this->b->id, $jobId);
        $this->assertNull(config('database.connections.tenant'));
        $this->connect($this->b);
        $this->assertSame(0, DB::connection('tenant')->table('fiscal_files')->count());
        try {
            app(PrivateFiscalFiles::class)->get($this->a->id, $document->id, 'qa_internal');
            $this->fail('Company B cannot read company A private file.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        try {
            app(PrivateFiscalFiles::class)->put($this->a->id, $document->id, 'qa_forged', 'MUST NOT BE WRITTEN');
            $this->fail('Company B cannot write into company A private path.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertCount(1, Storage::disk('fiscal')->allFiles());
        try {
            app(PrivateFiscalFiles::class)->get($this->b->id, $document->id, 'qa_internal');
            $this->fail('Matched company identity cannot read a foreign document.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        try {
            app(ProcessFiscalOutbox::class)->run($this->a->id, $jobId);
            $this->fail('Synthetic unsigned job must never reach transport.');
        } catch (FiscalTransportException) {
            $this->assertNull(config('database.connections.tenant'));
        }
        $this->connect($this->a);
        $this->assertSame(0, (int) DB::connection('tenant')->table('fiscal_outbox')->where('id', $jobId)->value('attempts'));
    }

    public function test_shared_importer_dry_run_backup_guard_and_real_two_pass_cutover_preserve_source_ids_and_hashes(): void
    {
        // This is a dedicated fixture source, not the immutable schema template A.
        $source = Tenant::create(['slug' => 'legacy-source', 'name' => 'Origem isolada QA', 'active' => true, 'storage_mode' => 'isolated', 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_b', 'db_username' => 'vendra_test', 'db_password' => env('VENDRA_TEST_PASSWORD')]);
        app(TenantConnection::class)->connect($source);
        $connection = DB::connection('tenant');
        if ($connection->getDatabaseName() !== 'vendra_t_test_b' || $connection->getConfig('username') !== 'vendra_test' || $connection->selectOne('SELECT DATABASE() AS db')->db !== 'vendra_t_test_b') {
            throw new \RuntimeException('Fixture source B refused before DDL.');
        }
        Artisan::call('migrate:fresh', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
        $owner = User::create(['name' => 'Responsável legado', 'email' => 'legacy-owner@example.test', 'password' => 'Legacy-test-pass-1234', 'role' => 'admin', 'must_change_password' => false]);
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Empresa legada preservada']);
        CompanyOperation::forceCreate(['id' => 1, 'version' => 0, 'configuration' => CompanyOperations::DEFAULTS]);
        $group = ProductGroup::create(['name' => 'Grupo legado', 'margin' => '40']);
        $product = Product::create(['name' => 'Produto legado', 'sku' => 'LEGACY-SKU', 'product_group_id' => $group->id, 'sale_price' => '100']);
        app(Inventory::class)->move($product->id, 'in', '2', '30', 'Estoque legado QA', $owner->id, (string) Str::uuid());
        $quote = app(Documents::class)->create(['type' => 'quote', 'request_key' => (string) Str::uuid(), 'items' => [['kind' => 'product', 'id' => $product->id, 'quantity' => '1', 'unit_price' => '100']]], $owner->id);
        app(Documents::class)->transition($quote->id, 'approved');
        $sale = app(Documents::class)->invoice($quote->id, ['payment_method' => 'invoice', 'paid' => false, 'due_date' => now()->addMonth()->toDateString()], $owner->id);
        $this->assertSame($sale->id, $quote->fresh()->sale_id);
        $this->assertSame($quote->id, $sale->document_id);
        $source->update(['active' => false]);
        $source = $source->fresh();
        app(TenantConnection::class)->disconnect();
        $before = $this->sourceDataHashes('vendra_t_test_b');
        $builder = new SharedSchemaBuilder(self::$admin);
        $importer = new SharedTenantImporter(self::$admin, $builder);
        $plan = $importer->run($source, self::$manifest);
        $this->assertSame('dry-run', $plan['mode']);
        $this->assertSame('isolated', $source->fresh()->storage_mode);
        try {
            $importer->run($source, self::$manifest, true);
            $this->fail('A real verified backup journal is mandatory.');
        } catch (\RuntimeException) {
            $this->assertSame('isolated', $source->fresh()->storage_mode);
        }
        $builder->assertEmptyNamespace(self::$database, $source->id, self::$manifest);
        $journal = $this->verifiedImportBackup($source);
        $result = (new SharedTenantImporter(self::$admin, $builder))->run($source, self::$manifest, true, $journal);
        $this->assertSame('cutover-confirmed', $result['status']);
        $this->assertSame($before, $this->sourceDataHashes('vendra_t_test_b'));
        $imported = $source->fresh();
        $this->assertSame('shared', $imported->storage_mode);
        $this->assertSame(self::$database, $imported->db_name);
        $this->assertFalse($imported->active);
        $this->assertNotNull($imported->provisioned_at);
        $imported->update(['active' => true]);
        app(TenantConnection::class)->connect($imported);
        $runtime = [];
        foreach (self::$manifest['tables'] as $table) {
            $rows = DB::connection('tenant')->table($table)->get()->map(fn ($row) => (array) $row)->all();
            $hashes = array_map(fn ($row) => Snapshot::hash($row), $rows);
            sort($hashes);
            $runtime[$table] = ['count' => count($hashes), 'sha256' => hash('sha256', implode("\n", $hashes))];
        }
        $this->assertSame($before, $runtime, 'Every copied row, nullable relationship and legacy ID must match the source.');
        $this->assertSame('legacy-owner@example.test', User::findOrFail($owner->id)->email);
        $this->assertSame($sale->id, Document::findOrFail($quote->id)->sale_id);
        $this->assertSame($quote->id, Sale::findOrFail($sale->id)->document_id);
        $this->assertSame('1.000', Product::findOrFail($product->id)->quantity);
        $this->assertSame(1, PlatformAudit::where('tenant_id', $imported->id)->where('action', 'tenant.shared_import')->count());
        $this->connect($this->a);
        $this->assertSame('owner@example.test', User::where('email', 'owner@example.test')->firstOrFail()->email);
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Sale::count());
    }

    /** Real private artifacts; the production quiescence guard is bypassed only by its existing narrow QA seam. */
    private function verifiedImportBackup(Tenant $source): string
    {
        if ($source->db_name !== 'vendra_t_test_b' || ! preg_match('/^vendra_shared_qa_[a-f0-9]{8}$/D', self::$database)) {
            throw new \RuntimeException('Non-QA backup refused.');
        }
        foreach (['/var/lib/vendra', '/var/lib/vendra/shared-import', '/var/backups/vendra'] as $parent) {
            if (! file_exists($parent) && ! mkdir($parent, 0700, true)) {
                throw new \RuntimeException('Cannot create private QA parent.');
            }
            if (is_link($parent) || fileowner($parent) !== 0 || (fileperms($parent) & 0022) !== 0) {
                throw new \RuntimeException('Unsafe QA backup parent.');
            }
        }
        $suffix = bin2hex(random_bytes(4));
        $journal = '/var/lib/vendra/shared-import/qa-'.$suffix;
        $backup = '/var/backups/vendra/'.gmdate('Ymd\THis\Z').'-QA'.$suffix;
        if (! mkdir($journal, 0700) || ! mkdir($backup, 0700)) {
            throw new \RuntimeException('QA backup path collision.');
        }
        $dump = new Process(['/usr/bin/mysqldump', '--no-defaults', '--user=root', '--protocol=SOCKET', '--socket=/var/run/mysqld/mysqld.sock', '--single-transaction', '--set-gtid-purged=OFF', '--no-tablespaces', '--databases', 'vendra_test_central', 'vendra_t_test_b'], base_path(), null, null, 60);
        $dump->run();
        if (! $dump->isSuccessful()) {
            throw new \RuntimeException('QA SQL backup failed.');
        }
        file_put_contents($backup.'/databases.sql', $dump->getOutput());
        chmod($backup.'/databases.sql', 0600);
        file_put_contents($backup.'/databases.txt', "vendra_test_central\nvendra_t_test_b\n");
        chmod($backup.'/databases.txt', 0600);
        $tar = new Process(['/usr/bin/tar', '-czf', $backup.'/application.tar.gz', 'app', 'bootstrap', 'config', 'database', 'resources', 'routes', 'public', 'tests', 'composer.json', 'composer.lock'], base_path(), null, null, 60);
        $tar->run();
        if (! $tar->isSuccessful()) {
            throw new \RuntimeException('QA application backup failed.');
        }chmod($backup.'/application.tar.gz', 0600);
        $lines = [];
        foreach (['databases.txt', 'databases.sql', 'application.tar.gz'] as $file) {
            if (filesize($backup.'/'.$file) < 1) {
                throw new \RuntimeException('Empty QA backup artifact.');
            }$lines[] = hash_file('sha256', $backup.'/'.$file).'  '.$file;
        }
        file_put_contents($backup.'/SHA256SUMS', implode("\n", $lines)."\n");
        chmod($backup.'/SHA256SUMS',0600);
        file_put_contents($journal.'/backup.json',json_encode(['status' => 'quiesced-verified', 'tenant_id' => (int) $source->id, 'source_database' => 'vendra_t_test_b', 'verified_at' => time(), 'backup_path' => $backup],JSON_THROW_ON_ERROR));
        chmod($journal.'/backup.json',0600);

        return $journal;
    }
}
