<?php

namespace Tests\Feature;

use App\Fiscal\CertificateInspector;
use App\Fiscal\CompanyFiscalConfiguration;
use App\Fiscal\FiscalReadiness;
use App\Fiscal\FiscalTransportException;
use App\Fiscal\PrepareFiscalReview;
use App\Fiscal\PrivateFiscalFiles;
use App\Fiscal\ProcessFiscalOutbox;
use App\Fiscal\QueueNfce;
use App\Fiscal\QueueVoid;
use App\Fiscal\Snapshot;
use App\Fiscal\SefazTransport;
use App\Models\BusinessProfile;
use App\Models\FiscalCredential;
use App\Models\FiscalDocument;
use App\Models\FiscalItemRule;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

class FiscalTest extends TestCase
{
    private static bool $schemasReady = false;

    private Tenant $tenant;

    private User $user;

    private Product $product;

    private Sale $sale;

    private SefazTransport $transport;
    private array $transportCalls = [];
    private string $submittedXml = '';


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
        return ['environment' => 'homologation', 'provider' => 'sefaz_direct', 'nfce_accredited' => '0', 'operation' => 'both', 'accountant_name' => 'Contador de teste'];
    }

    public function test_admin_can_configure_and_review_but_cannot_enable_production(): void
    {
        $this->login();
        $this->get('/app/a/fiscal')->assertOk()->assertSee('Conferências e pendências');
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
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Teste CNPJ alfa', 'document' => '12.ABC.345/01DE-35', 'state' => 'SP', 'municipality_code' => '3106200']);
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

    private function configuredFiscal(): array
    {
        Http::preventStrayRequests();
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'SIMULACAO SEM VALOR FISCAL', 'document' => '11.222.333/0001-81', 'state_registration' => '111111111111', 'tax_regime' => 'simples', 'street' => 'Rua de teste', 'number' => '1', 'district' => 'Teste', 'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '01001000', 'municipality_code' => '3550308']);
        FiscalSetting::forceCreate(['id' => 1, 'provider' => 'sefaz_direct', 'environment' => 'homologation', 'nfce_accredited' => true, 'operation' => 'presential', 'accountant_name' => 'Contador de teste', 'updated_by' => $this->user->id]);
        $this->product->update(['ncm' => '84713012', 'origin' => '0']);
        $certificate = FiscalFixtures::certificate();
        $issuer = app(CompanyFiscalConfiguration::class)->save($this->tenant, [
            'timezone' => 'America/Sao_Paulo', 'series' => 1, 'next_number' => 1, 'crt' => '1', 'natureza_operacao' => 'VENDA TESTE', 'identification_limit' => '10000.00', 'cancellation_minutes' => 30, 'uf_validation_reference' => 'Referência de teste sem validade fiscal', 'csc_required' => true,
        ], ['csc' => 'CSC-SYNTHETIC-NOT-A-SECRET', 'csc_id' => '000001', 'certificate_password' => $certificate['password']], $certificate['pfx']);
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        app(CompanyFiscalConfiguration::class)->validateLocal($this->tenant);
        $credential = $credential->fresh();
        FiscalItemRule::create(['product_id' => $this->product->id, 'parameters' => FiscalFixtures::rule(), 'accountant_reference' => 'Somente simulação de teste', 'validated_by' => $this->user->id]);

        $profile = BusinessProfile::findOrFail(1)->toArray();
        $this->transport = \Mockery::mock(SefazTransport::class.'[authorize,consult,cancel]', [$profile, $issuer->validated_configuration, $credential]);
        foreach (['authorize', 'consult', 'cancel'] as $method) {
            $this->transport->shouldReceive($method)->byDefault()->andReturnUsing(function (...$args) use ($method) {
                $this->transportCalls[] = $method;
                if ($method === 'authorize') { $this->submittedXml = $args[0]; }
                throw new FiscalTransportException('sefaz_connection_uncertain');
            });
        }
        $this->app->bind(SefazTransport::class, fn () => $this->transport);
        $this->sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true,
            'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01']]], $this->user->id);
        return [$issuer, $certificate];
    }

    private function forbidSimulationTransport(): void
    {
        Http::preventStrayRequests();
        foreach ([SefazTransport::class, CertificateInspector::class, \App\Fiscal\NfceXmlBuilder::class] as $service) {
            $this->app->bind($service, function () use ($service) {
                throw new \LogicException('Simulação interna tentou acessar certificado/transporte: '.$service);
            });
        }
    }

    private function simulationProtectedFacts(): array
    {
        $facts = [];
        foreach (['sales', 'sale_items', 'products', 'stock_movements', 'financial_entries', 'payments',
            'fiscal_issuers', 'fiscal_credentials', 'fiscal_documents', 'fiscal_sequences', 'fiscal_outbox', 'fiscal_files'] as $table) {
            $facts[$table] = DB::connection('tenant')->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $facts;
    }

    private function sharedFiscalProfileData(string $name, array $changes = []): array
    {
        $parameters = array_diff_key(FiscalFixtures::rule(), array_flip(['gtin', 'cest']));
        return ['name' => $name, 'active' => true, 'accountant_reference' => 'Perfil sintético para QA, sem validação contábil real',
            'parameters' => array_replace($parameters, $changes)];
    }

    private function fiscalProfileProduct(string $sku, array $changes = []): Product
    {
        return Product::create(array_replace(['name' => 'Produto '.$sku, 'sku' => $sku,
            'product_group_id' => $this->product->product_group_id, 'unit' => 'UN', 'sale_price' => '10.01',
            'ncm' => '84713012', 'origin' => '0', 'fiscal_gtin' => 'SEM GTIN', 'fiscal_cest' => '2106400'], $changes));
    }

    private function nfe55Configuration(): array
    {
        return ['timezone' => 'America/Sao_Paulo', 'series' => 1, 'next_number' => 1, 'crt' => '1',
            'natureza_operacao' => 'VENDA NF-E TESTE', 'cancellation_minutes' => 30, 'uf_validation_reference' => 'QA sintético, sem validação contábil real'];
    }

    private function nfe55Customer(): \App\Models\Customer
    {
        $customer = \App\Models\Customer::create(['name' => 'Destinatário sintético', 'document' => '52998224725']);
        return app(\App\Fiscal\NfeRecipient::class)->save($this->tenant, $customer->id, [
            'fiscal_street' => 'Rua de teste', 'fiscal_number' => '1', 'fiscal_district' => 'Centro', 'fiscal_city' => 'São Paulo',
            'fiscal_state' => 'SP', 'fiscal_postal_code' => '01001000', 'fiscal_municipality_code' => '3550308', 'fiscal_ie_indicator' => '9',
        ], $this->user->id);
    }

    private function useNfe55Transport(\App\Models\FiscalIssuer $issuer): void
    {
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $configuration = app(\App\Fiscal\NfeCompanyConfiguration::class)->forIssuer($issuer)->configuration;
        $this->transport = \Mockery::mock(SefazTransport::class.'[authorize,consult,cancel]', [BusinessProfile::findOrFail(1)->toArray(), $configuration, $credential, 55]);
        foreach (['authorize', 'consult', 'cancel'] as $method) {
            $this->transport->shouldReceive($method)->byDefault()->andReturnUsing(function (...$args) use ($method) {
                $this->transportCalls[] = $method;
                if ($method === 'authorize') { $this->submittedXml = $args[0]; }
                throw new FiscalTransportException('sefaz_connection_uncertain');
            });
        }
        $this->app->bind(SefazTransport::class, fn () => $this->transport);
    }

    private function configuredNfe55(): array
    {
        Http::preventStrayRequests();
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'SIMULACAO NF-E SEM VALOR FISCAL', 'document' => '11.222.333/0001-81',
            'state_registration' => '111111111111', 'tax_regime' => 'simples', 'street' => 'Rua de teste', 'number' => '1',
            'district' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '01001000', 'municipality_code' => '3550308']);
        $this->product->update(['ncm' => '84713012', 'origin' => '0']);
        $certificate = FiscalFixtures::certificate();
        $configuration = app(\App\Fiscal\NfeCompanyConfiguration::class);
        $model = $configuration->save($this->tenant, $this->nfe55Configuration(), ['certificate_password' => $certificate['password']], $certificate['pfx']);
        $configuration->validateLocal($this->tenant);
        $issuer = \App\Models\FiscalIssuer::findOrFail($model->issuer_id);
        FiscalItemRule::create(['product_id' => $this->product->id, 'parameters' => FiscalFixtures::rule(), 'accountant_reference' => 'Somente fixture sintética', 'validated_by' => $this->user->id]);
        $this->useNfe55Transport($issuer);
        $customer = $this->nfe55Customer();
        $this->sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'payment_method' => 'pix', 'paid' => true,
            'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01']]], $this->user->id);
        return [$issuer, $certificate, $customer];
    }

    private function operation(): array
    {
        return ['consumer_final' => true, 'not_taxpayer' => true, 'destination_state' => 'SP', 'presence' => '1', 'pix_mode' => 'dynamic'];
    }

    private function runFiscalWorker(int $id): void
    {
        app(ProcessFiscalOutbox::class)->run($this->tenant->id, $id);
        $this->assertNull(config('database.connections.tenant'));
        app(TenantConnection::class)->connect($this->tenant);
    }

    private function fakeAuthorization(FiscalDocument $doc, array $certificate): void
    {
        $this->transport->shouldReceive('authorize')->andReturnUsing(function (string $xml, string $key) {
            $this->assertSame(0, DB::connection('tenant')->transactionLevel(), 'SOAP dentro de transação.');
            $this->transportCalls[] = 'authorize'; $this->submittedXml = $xml;
            $this->assertStringContainsString('NFe'.$key, $xml);
            return ['request' => $xml, 'response' => FiscalFixtures::authorizationResponse($xml)];
        });
        $this->transport->shouldReceive('consult')->andReturnUsing(function (string $key) {
            $this->assertSame(0, DB::connection('tenant')->transactionLevel());
            $this->transportCalls[] = 'consult';
            $this->assertNotSame('', $this->submittedXml);
            $this->assertStringContainsString('NFe'.$key, $this->submittedXml);
            return ['request' => '', 'response' => FiscalFixtures::authorizationResponse($this->submittedXml, true)];
        });
    }

    public function test_private_certificate_matches_issuer_and_secrets_are_encrypted_and_hidden(): void
    {
        [$issuer,$cert] = $this->configuredFiscal();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $raw = DB::connection('tenant')->table('fiscal_credentials')->value('secrets');
        $this->assertStringNotContainsString('CSC-SYNTHETIC-NOT-A-SECRET', $raw);
        $this->assertStringNotContainsString('test-only-password', $raw);
        $this->assertArrayNotHasKey('secrets', $credential->toArray());
        $this->assertSame(base64_encode($cert['pfx']), $credential->secrets['pfx']);
        $this->expectException(ValidationException::class);
        app(CertificateInspector::class)->inspect($cert['pfx'], $cert['password'], '12222333000181');
    }

    public function test_emission_queue_is_idempotent_and_excludes_services_and_current_prices(): void
    {
        [$issuer] = $this->configuredFiscal();
        $queue = app(QueueNfce::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->product->update(['sale_price' => '1000']);
        $again = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->assertSame($doc->id, $again->id);
        $this->assertSame('10.01', $doc->snapshot['payload']['valor_total']);
        $this->assertSame('0.00', $doc->snapshot['services_total']);
        $this->assertCount(1, $doc->snapshot['payload']['items']);
        $this->assertNotSame('', $doc->signed_xml);
        $this->assertSame(hash('sha256', $doc->signed_xml), $doc->signed_xml_sha256);
        $this->assertStringNotContainsString('<NFe', DB::connection('tenant')->table('fiscal_documents')->where('id', $doc->id)->value('signed_xml'));
        $this->assertArrayNotHasKey('signed_xml', $doc->toArray());
        $this->assertSame($doc->signed_xml, $again->signed_xml);
        $this->assertSame([], $this->transportCalls);
        $this->assertSame('10.01', $doc->snapshot['payload']['formas_pagamento'][0]['valor_pagamento']);
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        Http::assertNothingSent();
    }

    public function test_signed_xml_with_changed_item_or_tax_is_rejected_even_with_same_total(): void
    {
        [, $cert] = $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $this->assertSame('authorized', $doc->fresh()->state);
        $xml = app(PrivateFiscalFiles::class)->get($this->tenant->id, $doc->id, 'xml');
        $credential = FiscalCredential::where('issuer_id', $doc->issuer_id)->firstOrFail();
        $payload = $doc->fresh()->snapshot['payload'] + ['_signed_xml' => $this->submittedXml];
        app(\App\Fiscal\XmlEvidence::class)->authorization($xml, $payload, $credential);
        foreach (['//n:det/n:prod/n:NCM' => '84715010', '//n:det/n:prod/n:cProd' => 'OTHER-SKU', '//n:det/n:imposto/n:ICMS/n:ICMSSN102/n:orig' => '1'] as $path => $value) {
            $dom = new \DOMDocument; $dom->loadXML($xml, LIBXML_NONET);
            $xp = new \DOMXPath($dom); $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $xp->query($path)->item(0)->nodeValue = $value;
            $signatures = $dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'Signature');
            $signature = $signatures->item(0); $signature->parentNode->removeChild($signature);
            $changed = \NFePHP\Common\Signer::sign(\NFePHP\Common\Certificate::readPfx($cert['pfx'], $cert['password']), $dom->saveXML(), 'infNFe', 'Id', OPENSSL_ALGO_SHA1, \NFePHP\Common\Signer::CANONICAL, 'NFe');
            $dom->loadXML($changed, LIBXML_NONET);
            $dom->getElementsByTagName('digVal')->item(0)->nodeValue = $dom->getElementsByTagName('DigestValue')->item(0)->nodeValue;
            $this->assertTrue(\NFePHP\Common\Signer::isSigned($dom->saveXML(), 'infNFe'), 'Adversarial deve manter assinatura válida.');
            try {
                app(\App\Fiscal\XmlEvidence::class)->authorization($dom->saveXML(), $payload, $credential);
                $this->fail('XML assinado divergente do conteúdo congelado deve falhar.');
            } catch (FiscalTransportException $exception) {
                $this->assertSame('authorization_xml_invalid', $exception->getMessage());
            }
        }
    }

    public function test_cancellation_evidence_rejects_wrong_event_sequence_key_and_unsigned_request(): void
    {
        [, $cert] = $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $doc = $doc->fresh();
        $exchange = FiscalFixtures::cancellationExchange($doc->access_key, $doc->protocol, 'Simulação de cancelamento sem valor fiscal', $cert);
        $xml = \NFePHP\NFe\Complements::toAuthorize($exchange['request'], $exchange['response']);
        $expected = ['_access_key' => $doc->access_key, '_protocol' => $doc->protocol, '_signed_event_xml' => $exchange['request'], 'cnpj_emitente' => '11222333000181'];
        $credential = FiscalCredential::where('issuer_id', $doc->issuer_id)->firstOrFail();
        $evidence = app(\App\Fiscal\XmlEvidence::class)->cancellation($xml, $expected, $credential);
        $this->assertSame('135', $evidence['code']);
        $expected['_certificate_fingerprint'] = $cert['fingerprint'];
        $rotated = FiscalFixtures::certificate();
        $rotatedCredential = clone $credential;
        $rotatedCredential->certificate_fingerprint = $rotated['fingerprint'];
        $oldEvidence = app(\App\Fiscal\XmlEvidence::class)->cancellation($xml, $expected, $rotatedCredential);
        $this->assertSame('135', $oldEvidence['code'], 'Certificado original continua verificável após rotação.');
        $rotatedExchange = FiscalFixtures::cancellationExchange($doc->access_key, $doc->protocol, 'Evento após rotação de certificado sem valor fiscal', $rotated);
        $rotatedXml = \NFePHP\NFe\Complements::toAuthorize($rotatedExchange['request'], $rotatedExchange['response']);
        $this->assertTrue(\NFePHP\Common\Signer::isSigned($rotatedXml, 'infEvento'));
        $rotatedExpected = array_replace($expected, ['_signed_event_xml' => $rotatedExchange['request']]);
        $this->assertSame('135', app(\App\Fiscal\XmlEvidence::class)->cancellation($rotatedXml, $rotatedExpected, $rotatedCredential)['code']);
        $unknown = FiscalFixtures::certificate();
        $unknownExchange = FiscalFixtures::cancellationExchange($doc->access_key, $doc->protocol, 'Terceiro certificado não cadastrado sem valor fiscal', $unknown);
        $unknownXml = \NFePHP\NFe\Complements::toAuthorize($unknownExchange['request'], $unknownExchange['response']);
        $this->assertTrue(\NFePHP\Common\Signer::isSigned($unknownXml, 'infEvento'));
        try {
            app(\App\Fiscal\XmlEvidence::class)->cancellation($unknownXml, array_replace($expected, ['_signed_event_xml' => $unknownExchange['request']]), $rotatedCredential);
            $this->fail('Um terceiro certificado não pinado não pode cancelar a nota.');
        } catch (FiscalTransportException $exception) {
            $this->assertSame('cancellation_xml_invalid', $exception->getMessage());
        }
        $unsigned = preg_replace('~<(?:\w+:)?Signature\b[^>]*>.*?</(?:\w+:)?Signature>~s', '', $xml);
        try {
            app(\App\Fiscal\XmlEvidence::class)->cancellation($unsigned, $expected, $credential);
            $this->fail('Evento sem assinatura não pode cancelar a nota.');
        } catch (FiscalTransportException $exception) {
            $this->assertSame('cancellation_xml_invalid', $exception->getMessage());
        }
        foreach (['nSeqEvento' => '2', 'chNFe' => str_repeat('1', 44), 'tpAmb' => '1'] as $field => $value) {
            $dom = new \DOMDocument; $dom->loadXML($xml, LIBXML_NONET);
            $xp = new \DOMXPath($dom); $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
            $xp->query('//n:retEvento/n:infEvento/n:'.$field)->item(0)->nodeValue = $value;
            try {
                app(\App\Fiscal\XmlEvidence::class)->cancellation($dom->saveXML(), $expected, $credential);
                $this->fail('Evento divergente não pode cancelar a nota.');
            } catch (FiscalTransportException $exception) {
                $this->assertSame('cancellation_xml_invalid', $exception->getMessage());
            }
        }
    }

    public function test_worker_authorizes_only_with_signed_matching_xml_and_serves_private_pdf(): void
    {
        [, $cert] = $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->fakeAuthorization($doc, $cert);
        $stock = $this->product->fresh()->quantity;
        $payments = DB::connection('tenant')->table('payments')->count();
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $doc = $doc->fresh();
        $this->assertSame('authorized', $doc->state, $doc->operator_message ?? '');
        $this->assertSame('135260000000001', $doc->protocol);
        $this->assertSame($stock, $this->product->fresh()->quantity);
        $this->assertSame($payments, DB::connection('tenant')->table('payments')->count());
        $this->assertStringStartsWith('%PDF', app(PrivateFiscalFiles::class)->get($this->tenant->id, $doc->id, 'danfe'));
        $file = DB::connection('tenant')->table('fiscal_files')->where('kind', 'xml')->first();
        $this->assertStringNotContainsString('<nfeProc', Storage::disk('fiscal')->get($file->private_path));
        $this->login();
        $this->get('/app/a/fiscal/documents/'.$doc->id.'/files/xml')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/app/a/fiscal/documents/'.$doc->id.'/files/danfe')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_timeout_is_consulted_without_second_submission_or_number(): void
    {
        [, $cert] = $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $id = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($id);
        $this->assertSame('uncertain', $doc->fresh()->state);
        $this->assertSame(['authorize'], $this->transportCalls);
        $request = $this->submittedXml;
        $this->assertNotSame('', $request);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker($id);
        $this->assertSame('authorized', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        $this->assertSame(['authorize', 'consult'], $this->transportCalls);
        $this->assertSame($request, $this->submittedXml);
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
    }

    public function test_processing_missing_receipts_and_malformed_responses_remain_uncertain(): void
    {
        $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->transport->shouldReceive('authorize')->andReturnUsing(function (string $xml) {
            $this->transportCalls[] = 'authorize'; $this->submittedXml = $xml;
            return ['request' => $xml, 'response' => '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>103</cStat><infRec><nRec>123456789012345</nRec></infRec></retEnviNFe>'];
        });
        $jobId = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($jobId);
        $this->assertSame('uncertain', $doc->fresh()->state);
        foreach (['105', '106', '104', 'malformed'] as $status) {
            $this->transport->shouldReceive('consult')->once()->andReturnUsing(function (string $key) use ($status) {
                $this->transportCalls[] = 'consult';
                return ['request' => '', 'response' => $status === 'malformed' ? '<soap:Envelope>invalid-sensitive-data' : '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>'.$status.'</cStat><chNFe>'.$key.'</chNFe></retConsSitNFe>'];
            });
            DB::connection('tenant')->table('fiscal_outbox')->where('id', $jobId)->update(['available_at' => now()->subMinute()]);
            $this->runFiscalWorker($jobId);
            $this->assertSame('uncertain', $doc->fresh()->state);
            $this->assertStringNotContainsString('sensitive-data', $doc->fresh()->operator_message ?? '');
        }
        $this->assertSame(1, count(array_filter($this->transportCalls, fn ($call) => $call === 'authorize')));
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
    }

    public function test_incomplete_soap_evidence_and_official_rejection_are_distinct(): void
    {
        $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->transport->shouldReceive('authorize')->andReturnUsing(function (string $xml) {
            $this->submittedXml = $xml;
            return ['request' => $xml, 'response' => '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>104</cStat></retEnviNFe>'];
        });
        $id = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($id);
        $this->assertSame('uncertain', $doc->fresh()->state);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->transport->shouldReceive('consult')->andReturnUsing(function (string $key) use ($doc) {
            $response = FiscalFixtures::authorizationResponse($this->submittedXml, true);
            $response = str_replace('<cStat>100</cStat><xMotivo>SIMULACAO NAO AUTORIZADA SEFAZ', '<cStat>704</cStat><xMotivo>SIMULACAO REJEITADA', $response);
            return ['request' => '', 'response' => $response];
        });
        $this->runFiscalWorker($id);
        $this->assertSame('rejected', $doc->fresh()->state);
        $this->assertSame('704', $doc->fresh()->official_code);
    }

    public function test_changed_issuer_configuration_blocks_transmission_and_missing_rules_allocate_nothing(): void
    {
        [$issuer] = $this->configuredFiscal();
        FiscalItemRule::query()->delete();
        try {
            app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
            $this->fail('Missing rule must block');
        } catch (ValidationException) {
            $this->assertSame(1, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        }
        FiscalItemRule::create(['product_id' => $this->product->id, 'parameters' => FiscalFixtures::rule(), 'accountant_reference' => 'TEST', 'validated_by' => $this->user->id]);
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        BusinessProfile::find(1)->update(['number' => '2']);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $this->assertSame('uncertain', $doc->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_new_service_in_unified_catalog_is_never_sent_as_goods(): void
    {
        $this->configuredFiscal();
        $service = Product::create(['name' => 'Serviço unificado', 'sku' => 'SERVICE', 'type' => 'service', 'controls_stock' => false, 'product_group_id' => $this->product->product_group_id, 'unit' => 'UN', 'sale_price' => '8']);
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true, 'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01'], ['kind' => 'product', 'id' => $service->id, 'quantity' => '1', 'unit_price' => '8']]], $this->user->id);
        try {
            app(QueueNfce::class)->enqueue($this->tenant, $sale->id, $this->user->id, $this->operation());
            $this->fail('Venda mista exige separação fiscal antes de transmitir.');
        } catch (ValidationException) {
            $this->assertSame(0, FiscalDocument::count());
            $this->assertSame([], $this->transportCalls);
        }
    }

    public function test_direct_configuration_keeps_certificate_local_and_cannot_enable_production(): void
    {
        [$issuer] = $this->configuredFiscal();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $this->assertSame('homologation', $issuer->environment);
        $this->assertArrayNotHasKey('emission_token', $credential->secrets);
        $this->assertArrayNotHasKey('management_token', $credential->secrets);
        Http::assertNothingSent();
        $this->login();
        $this->post('/app/a/fiscal/settings', array_replace($this->settings(), ['environment' => 'production']))->assertSessionHasErrors('environment');
    }

    public function test_operator_cannot_upload_certificate_issue_download_cancel_or_void(): void
    {
        $this->configuredFiscal();
        $this->user->update(['role' => 'operator']);
        $this->login();
        $this->post('/app/a/business/fiscal', [])->assertForbidden();
        $this->post('/app/a/business/fiscal/validate', [])->assertForbidden();
        $this->get('/app/a/fiscal/products/'.$this->product->id)->assertForbidden();
        $this->post('/app/a/fiscal/products/'.$this->product->id, [])->assertForbidden();
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/emit', [])->assertForbidden();
        $this->get('/app/a/fiscal/documents/1/files/xml')->assertForbidden();
        $this->post('/app/a/fiscal/documents/1/cancel', [])->assertForbidden();
        $this->post('/app/a/fiscal/voids', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_demo_production_and_wrong_pfx_password_are_blocked(): void
    {
        [$issuer,$certificate] = $this->configuredFiscal();
        try {
            app(CertificateInspector::class)->inspect($certificate['pfx'], 'wrong', '11222333000181');
            $this->fail('Wrong password must fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $issuer->update(['environment' => 'production']);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $this->assertSame('uncertain', $doc->fresh()->state);
        Http::assertNothingSent();
    }

    public function test_fiscal_cancel_preserves_sale_and_late_authorization_cannot_resurrect_note(): void
    {
        [, $cert] = $this->configuredFiscal();
        $queue = app(QueueNfce::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $doc = $doc->fresh();
        $this->assertSame('authorized', $doc->state);
        $stock = $this->product->fresh()->quantity;
        $payments = DB::connection('tenant')->table('payments')->count();
        $queue->cancel($this->tenant, $doc->id, 'Cancelamento fiscal de teste sem valor fiscal');
        $attempts = 0;
        $this->transport->shouldReceive('cancel')->andReturnUsing(function (string $key, string $protocol, string $reason) use ($cert, &$attempts) {
            $attempts++;
            if ($attempts === 1) { throw new FiscalTransportException('sefaz_connection_uncertain'); }
            return FiscalFixtures::cancellationExchange($key, $protocol, $reason, $cert);
        });
        $id = DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'cancel')->value('id');
        $this->runFiscalWorker($id);
        $this->assertSame('authorized', $doc->fresh()->state);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->runFiscalWorker($id);
        $this->assertSame('cancelled', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        $this->assertSame('completed', $this->sale->fresh()->status);
        $this->assertSame($stock, $this->product->fresh()->quantity);
        $this->assertSame($payments, DB::connection('tenant')->table('payments')->count());
        $this->assertStringContainsString('<cStat>135', app(PrivateFiscalFiles::class)->get($this->tenant->id, $doc->id, 'cancel_xml'));
        $queue->consult($this->tenant, $doc->id);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'consult')->value('id'));
        $this->assertSame('cancelled', $doc->fresh()->state);
    }

    public function test_correlated_cancellation_rejection_finishes_job_but_duplicate_or_forged_response_does_not(): void
    {
        [, $cert] = $this->configuredFiscal();
        $queue = app(QueueNfce::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $doc = $doc->fresh();
        $this->assertSame('authorized', $doc->state);
        $negative = function (string $status, bool $forged = false) use ($cert): void {
            $this->transport->shouldReceive('cancel')->once()->andReturnUsing(function (string $key, string $protocol, string $reason) use ($cert, $status, $forged) {
                $exchange = FiscalFixtures::cancellationExchange($key, $protocol, $reason, $cert);
                $exchange['response'] = str_replace('<cStat>135</cStat>', '<cStat>'.$status.'</cStat>', $exchange['response']);
                $exchange['response'] = str_replace('<nProt>135260000000002</nProt>', '', $exchange['response']);
                if ($forged) { $exchange['response'] = str_replace('<chNFe>'.$key.'</chNFe>', '<chNFe>'.str_repeat('1', 44).'</chNFe>', $exchange['response']); }
                return $exchange;
            });
        };
        $queue->cancel($this->tenant, $doc->id, 'Negativa sintética de cancelamento sem valor fiscal');
        $id = DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'cancel')->value('id');
        $negative('501');
        $this->runFiscalWorker($id);
        $this->assertSame('authorized', $doc->fresh()->state);
        $this->assertStringContainsString('Código: 501', $doc->fresh()->operator_message ?? '');
        $this->assertNotNull(DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->value('completed_at'));
        $queue->cancel($this->tenant, $doc->id, 'Nova solicitação após negativa definitiva sem valor fiscal');
        $nextId = DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'cancel')->orderByDesc('id')->value('id');
        $this->assertNotSame($id, $nextId);
        $negative('573');
        $this->runFiscalWorker($nextId);
        $this->assertNull(DB::connection('tenant')->table('fiscal_outbox')->where('id', $nextId)->value('completed_at'));
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $nextId)->update(['available_at' => now()->subMinute()]);
        $negative('501', true);
        $this->runFiscalWorker($nextId);
        $this->assertSame('authorized', $doc->fresh()->state);
        $this->assertNull(DB::connection('tenant')->table('fiscal_outbox')->where('id', $nextId)->value('completed_at'));
        $this->assertSame(1, FiscalDocument::count());
    }

    public function test_nfe55_without_csc_generates_signed_xml_authorizes_and_cancels_with_private_a4_danfe(): void
    {
        [$issuer, $cert] = $this->configuredNfe55();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $this->assertArrayNotHasKey('csc', $credential->secrets);
        $this->assertArrayNotHasKey('csc_id', $credential->secrets);
        $this->assertFalse($issuer->enabled);
        $this->assertFalse(app(FiscalReadiness::class)->inspect($this->tenant, $this->sale)['ready_to_transmit']);
        $queue = app(\App\Fiscal\QueueNfe::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->assertSame(55, $doc->model);
        $this->assertSame('55', substr($doc->access_key, 20, 2));
        $this->assertStringContainsString('<mod>55</mod>', $doc->signed_xml);
        $this->assertStringNotContainsString('infNFeSupl', $doc->signed_xml);
        $this->assertSame($doc->id, $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation())->id);
        $this->fakeAuthorization($doc, $cert);
        $before = [$this->product->fresh()->quantity, DB::connection('tenant')->table('payments')->count()];
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $this->assertSame('authorized', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        $pdf = app(PrivateFiscalFiles::class)->get($this->tenant->id, $doc->id, 'danfe');
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(1, preg_match('/\/MediaBox\s*\[\s*[0-9.]+\s+[0-9.]+\s+([0-9.]+)\s+([0-9.]+)/', $pdf, $page));
        $this->assertGreaterThan(500, (float) $page[1], 'DANFE 55 deve usar folha, não cupom NFC-e.');
        $queue->consult($this->tenant, $doc->id);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'consult')->value('id'));
        $this->assertSame('authorized', $doc->fresh()->state);
        $this->transport->shouldReceive('cancel')->andReturnUsing(fn (string $key, string $protocol, string $reason) => FiscalFixtures::cancellationExchange($key, $protocol, $reason, $cert));
        $queue->cancel($this->tenant, $doc->id, 'Cancelamento sintético NF-e sem valor fiscal');
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'cancel')->value('id'));
        $this->assertSame('cancelled', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        $this->assertSame($before, [$this->product->fresh()->quantity, DB::connection('tenant')->table('payments')->count()]);
        $this->login();
        $this->get('/app/a/fiscal/nfe/documents/'.$doc->id)->assertOk();
        $this->get('/app/a/fiscal/nfe/documents/'.$doc->id.'/files/danfe')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        Http::assertNothingSent();
    }

    public function test_nfe55_configuration_preserves_existing_65_hash_sequence_and_pins_cross_model_evidence(): void
    {
        [$issuer, $cert] = $this->configuredFiscal();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        // Persistent compatibility vector: this is the pre-NF55 approval payload.
        $legacyHash = Snapshot::hash(['issuer' => $issuer->document, 'configuration' => $issuer->validated_configuration,
            'profile' => BusinessProfile::findOrFail(1)->only(['legal_name', 'trade_name', 'document', 'state_registration', 'tax_regime', 'street', 'number', 'district', 'city', 'state', 'postal_code', 'municipality_code', 'email', 'phone']),
            'certificate' => $credential->certificate_fingerprint, 'credential_digest' => Snapshot::hash($credential->secrets)]);
        $credential->validated_hash = $legacyHash; $credential->save();
        $oldSecrets = Snapshot::hash($credential->secrets);
        $configuration = app(\App\Fiscal\NfeCompanyConfiguration::class);
        $configuration->save($this->tenant, $this->nfe55Configuration(), [], null);
        $configuration->validateLocal($this->tenant);
        $this->assertSame($legacyHash, app(CompanyFiscalConfiguration::class)->hash($issuer));
        $this->assertSame($legacyHash, $credential->fresh()->validated_hash);
        $this->assertSame($oldSecrets, Snapshot::hash($credential->fresh()->secrets));
        $doc65 = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $customer = $this->nfe55Customer();
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'customer_id' => $customer->id, 'payment_method' => 'pix', 'paid' => true,
            'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01']]], $this->user->id);
        $this->useNfe55Transport($issuer);
        $doc55 = app(\App\Fiscal\QueueNfe::class)->enqueue($this->tenant, $sale->id, $this->user->id, $this->operation());
        $this->assertSame(1, $doc55->number); $this->assertSame(1, $doc65->number);
        $this->assertSame([2, 2], DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $issuer->id)->orderBy('model')->pluck('next_number')->all());
        foreach ([$doc55, $doc65] as $doc) {
            $xml = \NFePHP\NFe\Complements::toAuthorize($doc->signed_xml, FiscalFixtures::authorizationResponse($doc->signed_xml));
            $payload = $doc->snapshot['payload'] + ['_signed_xml' => $doc->signed_xml, '_certificate_fingerprint' => $doc->snapshot['certificate_fingerprint']];
            $this->assertTrue(\NFePHP\Common\Signer::isSigned($xml, 'infNFe'));
            app(\App\Fiscal\XmlEvidence::class)->authorization($xml, $payload, $credential->fresh(), (int) $doc->model);
            try {
                app(\App\Fiscal\XmlEvidence::class)->authorization($xml, $payload, $credential->fresh(), $doc->model === 55 ? 65 : 55);
                $this->fail('XML válido de outro modelo não pode autorizar este documento.');
            } catch (FiscalTransportException) { $this->assertTrue(true); }
        }
        $replacement = FiscalFixtures::certificate();
        $configuration->save($this->tenant, array_replace($this->nfe55Configuration(), ['next_number' => 2]), ['certificate_password' => $replacement['password']], $replacement['pfx']);
        $this->assertNull($credential->fresh()->validated_hash, 'Troca de A1 exige revalidação do 65.');
        $this->assertNull($configuration->forIssuer($issuer)->validated_hash);
        $this->assertSame($doc65->signed_xml, $doc65->fresh()->signed_xml);
    }

    public function test_nfe55_requires_valid_recipient_address_taxpayer_status_and_same_state(): void
    {
        [, , $customer] = $this->configuredNfe55();
        $queue = app(\App\Fiscal\QueueNfe::class);
        $attempt = function () use ($queue): void {
            try { $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation()); $this->fail('Destinatário incompleto ou incompatível deve bloquear a emissão.'); }
            catch (ValidationException) {
                $this->assertSame(0, FiscalDocument::count());
                $this->assertSame(1, DB::connection('tenant')->table('fiscal_sequences')->where('model', 55)->value('next_number'));
            }
        };
        $this->sale->update(['customer_id' => null]); $attempt();
        $this->sale->update(['customer_id' => $customer->id]);
        $customer->update(['fiscal_street' => null]); $attempt();
        $customer->update(['fiscal_street' => 'Rua de teste', 'fiscal_state' => 'MG', 'fiscal_municipality_code' => '3106200']); $attempt();
        $customer->update(['fiscal_state' => 'SP', 'fiscal_municipality_code' => '3106200']); $attempt();
        $customer->update(['fiscal_municipality_code' => '3550308', 'fiscal_ie_indicator' => '1', 'fiscal_state_registration' => '111111111111']); $attempt();
        $customer->update(['fiscal_ie_indicator' => '9', 'fiscal_state_registration' => null]);
        try { $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, array_replace($this->operation(), ['consumer_final' => false])); $this->fail('Não contribuinte exige consumidor final neste escopo.'); }
        catch (ValidationException) { $this->assertSame(0, FiscalDocument::count()); }
        $this->assertSame([], $this->transportCalls);
    }

    public function test_nfe55_non100_protocols_remain_uncertain_and_block_other_model_or_issuer(): void
    {
        $this->configuredNfe55();
        $queue = app(\App\Fiscal\QueueNfe::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $codes = ['150', '120', '110', '205', '301', '302', '303'];
        $this->transport->shouldReceive('authorize')->andReturnUsing(function (string $xml) {
            $this->submittedXml = $xml; $this->transportCalls[] = 'authorize';
            return ['request' => $xml, 'response' => str_replace('<cStat>100</cStat>', '<cStat>150</cStat>', FiscalFixtures::authorizationResponse($xml))];
        });
        $job = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($job);
        foreach ($codes as $code) {
            $this->transport->shouldReceive('consult')->once()->andReturnUsing(fn () => ['request' => '', 'response' => str_replace('<cStat>100</cStat>', '<cStat>'.$code.'</cStat>', FiscalFixtures::authorizationResponse($this->submittedXml, true))]);
            DB::connection('tenant')->table('fiscal_outbox')->where('id', $job)->update(['available_at' => now()->subMinute()]);
            $this->runFiscalWorker($job);
            $this->assertSame('uncertain', $doc->fresh()->state, 'Protocolo '.$code.' não pode ser tratado como rejeição liberando outro modelo.');
            try { app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation()); $this->fail('Uma venda incerta não pode ganhar outro modelo.'); }
            catch (ValidationException $exception) { $this->assertStringContainsString('já possui', implode(' ', $exception->errors()['fiscal'])); }
        }
        BusinessProfile::findOrFail(1)->update(['document' => '04.252.011/0001-10']);
        $certificate = FiscalFixtures::certificate('04252011000110');
        $configuration = app(\App\Fiscal\NfeCompanyConfiguration::class);
        $newIssuer = $configuration->save($this->tenant, $this->nfe55Configuration(), ['certificate_password' => $certificate['password']], $certificate['pfx']);
        $configuration->validateLocal($this->tenant);
        try { $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation()); $this->fail('Trocar CNPJ não libera nova emissão para a mesma venda incerta.'); }
        catch (ValidationException $exception) { $this->assertStringContainsString('já possui', implode(' ', $exception->errors()['fiscal'])); }
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_sequences')->where('issuer_id', $newIssuer->issuer_id)->where('model', 55)->value('next_number'));
        $this->assertSame(['authorize'], $this->transportCalls);
    }

    public function test_nfe55_and_65_competing_for_same_sale_allocate_only_one_document(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Concorrência real exige pcntl.'); }
        [$issuer] = $this->configuredFiscal();
        $configuration = app(\App\Fiscal\NfeCompanyConfiguration::class);
        $configuration->save($this->tenant, $this->nfe55Configuration(), [], null);
        $configuration->validateLocal($this->tenant);
        $customer = $this->nfe55Customer(); $this->sale->update(['customer_id' => $customer->id]);
        $this->app->bind(SefazTransport::class, function ($app, array $parameters) {
            $transport = \Mockery::mock(SefazTransport::class.'[authorize,consult,cancel]', [$parameters['issuer'], $parameters['configuration'], $parameters['credential'], $parameters['model'] ?? 65]);
            foreach (['authorize', 'consult', 'cancel'] as $method) { $transport->shouldReceive($method)->byDefault()->andThrow(new FiscalTransportException('unexpected_network_during_queue')); }
            return $transport;
        });
        $results = $this->raceEmission(function (int $worker) {
            try {
                $queue = $worker === 0 ? app(\App\Fiscal\QueueNfe::class) : app(QueueNfce::class);
                return (int) $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation())->model;
            } catch (ValidationException $exception) {
                if (! str_contains(implode(' ', $exception->errors()['fiscal'] ?? []), 'já possui')) { throw $exception; }
                return 0;
            }
        });
        $winners = array_values(array_filter($results));
        $this->assertCount(1, $winners);
        $this->assertContains($winners[0], [55, 65]);
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->assertSame(3, (int) DB::connection('tenant')->table('fiscal_sequences')->sum('next_number'));
        Http::assertNothingSent();
    }

    public function test_nfe55_http_rejects_csc_wrong_certificate_operator_and_cross_tenant_access(): void
    {
        [$issuer, $certificate, $customer] = $this->configuredNfe55();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $approved = app(\App\Fiscal\NfeCompanyConfiguration::class)->forIssuer($issuer)->validated_hash;
        $wrong = FiscalFixtures::certificate('04252011000110');
        try {
            app(\App\Fiscal\NfeCompanyConfiguration::class)->save($this->tenant, $this->nfe55Configuration(), ['certificate_password' => $wrong['password']], $wrong['pfx']);
            $this->fail('A1 de outra empresa não pode substituir o certificado.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('certificate', $exception->errors()); }
        $this->assertSame($certificate['fingerprint'], $credential->fresh()->certificate_fingerprint);
        $this->assertSame($approved, app(\App\Fiscal\NfeCompanyConfiguration::class)->forIssuer($issuer)->validated_hash);
        $this->login();
        $seriesInForm = function ($response, string $marker): string {
            $dom = new \DOMDocument;
            @$dom->loadHTML($response->getContent(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);
            $inputs = $xpath->query('//form[.//input[@name="_fiscal_form" and @value="'.$marker.'"]]//input[@name="series"]');
            $this->assertSame(1, $inputs->length);
            return $inputs->item(0)->getAttribute('value');
        };
        $this->post('/app/a/business/fiscal/nfe', array_replace($this->nfe55Configuration(), ['series' => 7, 'environment' => 'homologation', '_fiscal_form' => 'nfe55', 'csc' => 'FORBIDDEN-SYNTHETIC']))
            ->assertSessionHasErrors('csc')->assertSessionMissing('_old_input.csc');
        $business = $this->get('/app/a/business')->assertOk();
        $this->assertSame('7', $seriesInForm($business, 'nfe55'));
        $this->assertNotSame('7', $seriesInForm($business, 'nfce65'));
        $this->post('/app/a/business/fiscal', ['environment' => 'homologation', 'intent' => 'activate', '_fiscal_form' => 'nfce65', 'series' => 8])->assertSessionHasErrors('crt');
        $business = $this->get('/app/a/business')->assertOk();
        $this->assertSame('8', $seriesInForm($business, 'nfce65'));
        $this->assertSame('1', $seriesInForm($business, 'nfe55'));
        $this->get('/app/a/fiscal/nfe/customers/'.$customer->id)->assertOk();
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/nfe/emit', ['homologation_consent' => '1', 'consumer_final' => '1', 'presence' => '1', 'destination_state' => 'SP', 'pix_mode' => 'dynamic'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $doc = FiscalDocument::firstOrFail();
        $this->assertSame(55, $doc->model);
        $this->assertSame('pending', $doc->state);
        $this->assertSame([], $this->transportCalls);
        $this->user->update(['role' => 'operator']);
        foreach (['/app/a/fiscal/nfe/customers/'.$customer->id, '/app/a/fiscal/nfe/documents/'.$doc->id, '/app/a/fiscal/nfe/documents/'.$doc->id.'/files/xml'] as $url) { $this->get($url)->assertForbidden(); }
        foreach (['/app/a/business/fiscal/nfe', '/app/a/business/fiscal/nfe/validate', '/app/a/fiscal/nfe/customers/'.$customer->id,
            '/app/a/sales/'.$this->sale->id.'/fiscal/nfe/emit', '/app/a/fiscal/nfe/documents/'.$doc->id.'/consult', '/app/a/fiscal/nfe/documents/'.$doc->id.'/cancel'] as $url) { $this->post($url, [])->assertForbidden(); }
        app(TenantConnection::class)->connect($this->tenant); $this->user->update(['role' => 'admin']);
        $other = Tenant::where('slug', 'b')->firstOrFail(); app(TenantConnection::class)->connect($other);
        $userB = User::forceCreate(['id' => $this->user->id, 'name' => 'Admin B', 'email' => 'nfe-b@example.test', 'password' => 'other-password-test', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $userB->id, 'auth_version' => 0]);
        $this->get('/app/b/fiscal/nfe/documents/'.$doc->id)->assertNotFound();
        $this->get('/app/b/fiscal/nfe/customers/'.$customer->id)->assertNotFound();
        $this->post('/app/b/business/fiscal/nfe', $this->nfe55Configuration() + ['environment' => 'homologation', 'tenant_id' => $this->tenant->id])->assertSessionHasErrors('tenant_id');
        app(TenantConnection::class)->connect($other);
        try { app(\App\Fiscal\NfeCompanyConfiguration::class)->save($this->tenant, $this->nfe55Configuration(), [], null); $this->fail('Configuração não pode usar conexão de outra empresa.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertSame(0, FiscalDocument::count());
        Http::assertNothingSent();
    }

    public function test_tax_profiles_precedence_inheritance_and_inactive_bindings_fail_closed(): void
    {
        $this->forbidSimulationTransport();
        $manager = app(\App\Fiscal\FiscalProfileManager::class);
        $resolver = app(\App\Fiscal\FiscalRuleResolver::class);
        $groupProfile = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Grupo QA'));
        $directProfile = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Direto QA', ['transparency_rate' => '1']));
        $group = ProductGroup::findOrFail($this->product->product_group_id);
        $manager->assignGroup($this->tenant, $this->user->id, $group->id, $groupProfile->id, $manager->groupBindingHash($group), (string) Str::uuid());
        $product = $this->fiscalProfileProduct('PROFILE-PRIORITY', ['fiscal_gtin' => '7894900011517']);
        $manager->bindProduct($this->tenant, $this->user->id, $product->id, $directProfile->id, $product->fiscal_gtin, $product->fiscal_cest, $manager->bindingHash($product), (string) Str::uuid());
        FiscalItemRule::create(['product_id' => $product->id, 'parameters' => array_replace(FiscalFixtures::rule(), ['transparency_rate' => '2', 'cest' => '0100100']),
            'accountant_reference' => 'Exceção individual sintética', 'validated_by' => $this->user->id]);
        $individual = $resolver->resolve($product->fresh());
        $this->assertSame('individual', $individual['source']);
        $this->assertTrue($individual['available']);
        $this->assertSame(0, bccomp((string) $individual['parameters']['transparency_rate'], '2', 4));
        $this->assertSame('7894900011517', $individual['parameters']['gtin']);
        $this->assertSame('2106400', $individual['parameters']['cest']);
        try {
            $manager->bindProduct($this->tenant, $this->user->id, $product->id, $directProfile->id, null, null, $manager->bindingHash($product->fresh()), (string) Str::uuid());
            $this->fail('Vínculo não pode limpar o GTIN obrigatório de uma exceção individual.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('fiscal_gtin', $exception->errors()); }
        $this->assertSame('7894900011517', $product->fresh()->fiscal_gtin);
        $manager->bindProduct($this->tenant, $this->user->id, $product->id, $directProfile->id, '7894900011517', null, $manager->bindingHash($product->fresh()), (string) Str::uuid());
        $this->assertNull($resolver->resolve($product->fresh())['parameters']['cest'] ?? null);
        FiscalItemRule::where('product_id', $product->id)->delete();
        $direct = $resolver->resolve($product->fresh());
        $this->assertSame('product_profile', $direct['source']);
        $this->assertSame($directProfile->id, $direct['profile_id']);
        $manager->save($this->tenant, $this->user->id, array_replace($this->sharedFiscalProfileData('Direto QA', ['transparency_rate' => '1']), ['active' => false]), $directProfile->id, $directProfile->revision);
        $blocked = $resolver->resolve($product->fresh());
        $this->assertFalse($blocked['available']);
        $this->assertSame($directProfile->id, $blocked['profile_id']);
        $this->assertSame('product_profile', $blocked['source']);
        $new = $this->fiscalProfileProduct('PROFILE-NEW', ['fiscal_gtin' => null]);
        $inherited = $resolver->resolve($new);
        $this->assertSame('group_profile', $inherited['source']);
        $this->assertSame($groupProfile->id, $inherited['profile_id']);
        $this->assertFalse($inherited['available']);
        $manager->bindProduct($this->tenant, $this->user->id, $new->id, null, 'SEM GTIN', $new->fiscal_cest, $manager->bindingHash($new), (string) Str::uuid());
        $this->assertTrue($resolver->resolve($new->fresh())['available']);
        $this->assertNull($new->fresh()->fiscal_tax_profile_id);
        Http::assertNothingSent();
    }

    public function test_tax_profiles_bulk_rejects_stale_previews_atomically_and_preserves_exceptions_and_identity(): void
    {
        $this->forbidSimulationTransport();
        $manager = app(\App\Fiscal\FiscalProfileManager::class);
        $target = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Alvo QA'));
        $other = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Exceção de perfil QA'));
        $a = $this->fiscalProfileProduct('BULK-A', ['fiscal_gtin' => '7894900011517']);
        $b = $this->fiscalProfileProduct('BULK-B');
        $c = $this->fiscalProfileProduct('BULK-C');
        $d = $this->fiscalProfileProduct('BULK-D');
        $individual = FiscalItemRule::create(['product_id' => $b->id, 'parameters' => FiscalFixtures::rule(), 'accountant_reference' => 'Exceção preservada', 'validated_by' => $this->user->id]);
        $manager->bindProduct($this->tenant, $this->user->id, $c->id, $other->id, $c->fiscal_gtin, $c->fiscal_cest, $manager->bindingHash($c), (string) Str::uuid());
        $ids = [$a->id, $b->id, $c->id, $d->id];
        $identityColumns = ['id', 'ncm', 'origin', 'unit', 'fiscal_gtin', 'fiscal_cest', 'quantity', 'average_cost', 'sale_price'];
        $identities = Product::whereIn('id', $ids)->orderBy('id')->get($identityColumns)->toArray();
        $individualHash = Snapshot::hash($individual->parameters);
        $preview = $manager->preview($this->tenant, $this->user->id, $target->id, $ids);
        $this->assertSame(2, $preview['applied']);
        $this->assertSame(2, $preview['skipped']);
        $target = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Alvo QA', ['transparency_rate' => '1']), $target->id, $target->revision);
        try {
            $manager->apply($this->tenant, $this->user->id, $target->id, $ids, $preview['hash'], (string) Str::uuid());
            $this->fail('Edição do perfil invalida toda a prévia.');
        } catch (ValidationException) { $this->assertNull($a->fresh()->fiscal_tax_profile_id); $this->assertNull($d->fresh()->fiscal_tax_profile_id); }
        $preview = $manager->preview($this->tenant, $this->user->id, $target->id, $ids);
        $manager->bindProduct($this->tenant, $this->user->id, $d->id, $other->id, $d->fiscal_gtin, $d->fiscal_cest, $manager->bindingHash($d), (string) Str::uuid());
        try {
            $manager->apply($this->tenant, $this->user->id, $target->id, $ids, $preview['hash'], (string) Str::uuid());
            $this->fail('Mudança de vínculo de um produto invalida a aplicação inteira.');
        } catch (ValidationException) { $this->assertNull($a->fresh()->fiscal_tax_profile_id); }
        $preview = $manager->preview($this->tenant, $this->user->id, $target->id, $ids);
        $key = (string) Str::uuid();
        $result = $manager->apply($this->tenant, $this->user->id, $target->id, $ids, $preview['hash'], $key);
        $this->assertSame(1, $result['applied']); $this->assertSame(3, $result['skipped']);
        $auditCount = \App\Models\FiscalTaxProfileAudit::count();
        $this->assertSame(Snapshot::hash($result), Snapshot::hash($manager->apply($this->tenant, $this->user->id, $target->id, $ids, $preview['hash'], $key)));
        $this->assertSame($auditCount, \App\Models\FiscalTaxProfileAudit::count());
        $this->assertSame($target->id, $a->fresh()->fiscal_tax_profile_id);
        $this->assertNull($b->fresh()->fiscal_tax_profile_id);
        $this->assertSame($other->id, $c->fresh()->fiscal_tax_profile_id);
        $this->assertSame($other->id, $d->fresh()->fiscal_tax_profile_id);
        $this->assertSame($identities, Product::whereIn('id', $ids)->orderBy('id')->get($identityColumns)->toArray());
        $this->assertSame($individualHash, Snapshot::hash($individual->fresh()->parameters));
        $replacePreview = $manager->preview($this->tenant, $this->user->id, $other->id, $ids, true);
        $replacement = $manager->apply($this->tenant, $this->user->id, $other->id, $ids, $replacePreview['hash'], (string) Str::uuid(), true);
        $this->assertSame(1, $replacement['applied']);
        $this->assertSame($other->id, $a->fresh()->fiscal_tax_profile_id);
        $this->assertNull($b->fresh()->fiscal_tax_profile_id);
        $this->assertSame($individualHash, Snapshot::hash($individual->fresh()->parameters));
        $this->assertSame($identities, Product::whereIn('id', $ids)->orderBy('id')->get($identityColumns)->toArray());
        foreach (['gtin', 'cest', 'ncm', 'origin', 'unit'] as $field) { $this->assertArrayNotHasKey($field, $target->parameters); }
        Http::assertNothingSent();
    }

    public function test_tax_profiles_frozen_nfce_and_simulation_snapshots_keep_original_profile_revision(): void
    {
        $this->configuredFiscal();
        $manager = app(\App\Fiscal\FiscalProfileManager::class);
        $profile = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Histórico QA'));
        FiscalItemRule::where('product_id', $this->product->id)->delete();
        $manager->bindProduct($this->tenant, $this->user->id, $this->product->id, $profile->id, 'SEM GTIN', null, $manager->bindingHash($this->product->fresh()), (string) Str::uuid());
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $simulation = app(\App\Fiscal\SimulationFiscal::class)->create($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
        $oldSnapshot = Snapshot::hash($doc->snapshot);
        $oldXml = $doc->signed_xml;
        $oldSimulation = Snapshot::hash($simulation->snapshot);
        $rule = $doc->snapshot['tax_rules'][$this->product->id];
        $this->assertSame($profile->id, $rule['profile_id']);
        $this->assertSame($profile->revision, $rule['profile_revision']);
        $updated = $manager->save($this->tenant, $this->user->id, $this->sharedFiscalProfileData('Histórico QA', ['transparency_rate' => '1']), $profile->id, $profile->revision);
        $this->assertSame($profile->revision + 1, $updated->revision);
        $this->assertSame($oldSnapshot, Snapshot::hash($doc->fresh()->snapshot));
        $this->assertSame($oldXml, $doc->fresh()->signed_xml);
        $this->assertSame($oldSimulation, Snapshot::hash($simulation->fresh()->snapshot));
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true,
            'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01']]], $this->user->id);
        $next = app(QueueNfce::class)->enqueue($this->tenant, $sale->id, $this->user->id, $this->operation());
        $nextRule = $next->snapshot['tax_rules'][$this->product->id];
        $this->assertSame($updated->revision, $nextRule['profile_revision']);
        $this->assertSame(0, bccomp((string) $nextRule['parameters']['transparency_rate'], '1', 4));
        $this->assertSame(0, bccomp((string) $rule['parameters']['transparency_rate'], '0', 4));
        $this->assertSame([], $this->transportCalls);
        Http::assertNothingSent();
    }

    public function test_tax_profiles_http_permissions_tenant_isolation_and_shared_metadata_are_enforced(): void
    {
        $this->forbidSimulationTransport();
        $manager = app(\App\Fiscal\FiscalProfileManager::class);
        foreach (['gtin' => 'SEM GTIN', 'cest' => '2106400', 'ncm' => '84713012', 'origin' => '0', 'unit' => 'UN'] as $field => $value) {
            $invalid = $this->sharedFiscalProfileData('Metadado proibido QA');
            $invalid['parameters'][$field] = $value;
            try { $manager->save($this->tenant, $this->user->id, $invalid); $this->fail('Identidade de produto não pode virar parâmetro compartilhado.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey($field, $exception->errors()); }
        }
        $this->assertSame(0, \App\Models\FiscalTaxProfile::count());
        $this->login();
        $data = $this->sharedFiscalProfileData('Perfil HTTP QA');
        $form = array_merge($data['parameters'], ['name' => $data['name'], 'active' => '1', 'accountant_reference' => $data['accountant_reference']]);
        $this->post('/app/a/fiscal/profiles', $form)->assertRedirect('/app/a/fiscal/profiles')->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $profile = \App\Models\FiscalTaxProfile::firstOrFail();
        $product = $this->fiscalProfileProduct('PROFILE-HTTP');
        foreach (['/app/a/fiscal/profiles', '/app/a/fiscal/profiles/create', '/app/a/fiscal/profiles/'.$profile->id.'/edit', '/app/a/fiscal/profiles/'.$profile->id.'/bulk'] as $url) { $this->get($url)->assertOk(); }
        $previewResponse = $this->post('/app/a/fiscal/profiles/'.$profile->id.'/preview', ['product_ids' => [$product->id], 'replace_existing' => '0'])->assertOk();
        $preview = $previewResponse->viewData('preview');
        $apply = ['product_ids' => [$product->id], 'replace_existing' => '0', 'request_key' => (string) Str::uuid(), 'expected_hash' => $preview['hash']];
        $previewUrl = '/app/a/fiscal/profiles/'.$profile->id.'/preview';
        $this->from($previewUrl)->post('/app/a/fiscal/profiles/'.$profile->id.'/apply', $apply)->assertSessionHasErrors('confirm')->assertRedirect($previewUrl);
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertNull($product->fresh()->fiscal_tax_profile_id);
        $this->get($previewUrl)->assertOk();
        $this->post('/app/a/fiscal/profiles/'.$profile->id.'/apply', $apply + ['confirm' => '1'])->assertRedirect('/app/a/fiscal/profiles')->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($profile->id, $product->fresh()->fiscal_tax_profile_id);
        $this->user->update(['role' => 'operator']);
        foreach (['/app/a/fiscal/profiles', '/app/a/fiscal/profiles/create', '/app/a/fiscal/profiles/'.$profile->id.'/edit', $previewUrl] as $url) { $this->get($url)->assertForbidden(); }
        foreach (['/app/a/fiscal/profiles', '/app/a/fiscal/profiles/'.$profile->id.'/preview', '/app/a/fiscal/profiles/'.$profile->id.'/apply',
            '/app/a/fiscal/products/'.$product->id.'/profile', '/app/a/fiscal/groups/'.$product->product_group_id.'/profile'] as $url) { $this->post($url, [])->assertForbidden(); }
        app(TenantConnection::class)->connect($this->tenant);
        $this->user->update(['role' => 'admin']);
        $other = Tenant::where('slug', 'b')->firstOrFail();
        app(TenantConnection::class)->connect($other);
        $otherUser = User::forceCreate(['id' => $this->user->id, 'name' => 'Admin B', 'email' => 'profiles-b@example.test', 'password' => 'profile-password-test', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $otherUser->id, 'auth_version' => 0]);
        $this->get('/app/b/fiscal/profiles/'.$profile->id.'/edit')->assertNotFound();
        $this->post('/app/b/fiscal/profiles', $form + ['tenant_id' => $this->tenant->id])->assertSessionHasErrors('tenant_id');
        app(TenantConnection::class)->connect($other);
        try { $manager->preview($this->tenant, $otherUser->id, $profile->id, [$product->id]); $this->fail('Conexão de outra empresa deve ser rejeitada.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertSame(0, \App\Models\FiscalTaxProfile::count());
        Http::assertNothingSent();
    }

    public function test_internal_simulation_scenarios_consult_and_cancel_have_no_real_fiscal_or_commercial_effects(): void
    {
        $this->forbidSimulationTransport();
        $before = $this->simulationProtectedFacts();
        $service = app(\App\Fiscal\SimulationFiscal::class);
        $records = [];
        foreach (['accepted', 'rejected', 'uncertain'] as $scenario) {
            $records[$scenario] = $service->create($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid(), $scenario);
            $this->assertSame($scenario, $records[$scenario]->state);
            $this->assertSame('VendraSimulation', $records[$scenario]->snapshot['schema']);
            $this->assertSame('internal', $records[$scenario]->snapshot['environment']);
            $this->assertFalse($records[$scenario]->snapshot['readiness']['ready_to_transmit']);
        }
        foreach (['rejected', 'uncertain'] as $state) {
            try {
                $service->cancel($this->tenant, $records[$state]->id, $this->user->id, 'Cancelamento inválido antes de aceite', (string) Str::uuid());
                $this->fail('Somente simulação aceita pode ser cancelada.');
            } catch (ValidationException $exception) { $this->assertArrayHasKey('simulation', $exception->errors()); }
        }
        $uncertainSnapshot = $records['uncertain']->snapshot;
        $consultKey = (string) Str::uuid();
        $accepted = $service->consult($this->tenant, $records['uncertain']->id, $this->user->id, $consultKey);
        $this->assertSame('accepted', $accepted->state);
        $this->assertSame(Snapshot::hash($uncertainSnapshot), Snapshot::hash($accepted->snapshot));
        $this->assertSame($accepted->id, $service->consult($this->tenant, $accepted->id, $this->user->id, $consultKey)->id);
        $this->assertSame('rejected', $service->consult($this->tenant, $records['rejected']->id, $this->user->id, (string) Str::uuid())->state);
        $cancelled = $service->cancel($this->tenant, $records['accepted']->id, $this->user->id, 'Cancelamento exclusivamente de treinamento', (string) Str::uuid());
        $this->assertSame('cancelled', $cancelled->state);
        $this->assertSame($before, $this->simulationProtectedFacts());
        $this->assertSame(3, \App\Models\FiscalSimulation::count());
        Http::assertNothingSent();
    }

    public function test_internal_simulation_idempotency_distinguishes_new_exercises_and_rejects_reused_payloads(): void
    {
        $this->forbidSimulationTransport();
        $service = app(\App\Fiscal\SimulationFiscal::class);
        $key = (string) Str::uuid();
        $first = $service->create($this->tenant, $this->sale->id, $this->user->id, $key);
        $this->assertSame($first->id, $service->create($this->tenant, $this->sale->id, $this->user->id, $key)->id);
        $second = $service->create($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, \App\Models\FiscalSimulationEvent::count());
        foreach ([fn () => $service->create($this->tenant, $this->sale->id, $this->user->id, $key, 'rejected'),
            fn () => $service->consult($this->tenant, $second->id, $this->user->id, $key)] as $attempt) {
            try { $attempt(); $this->fail('UUID usado para outro payload/operação deve ser rejeitado.'); }
            catch (ValidationException $exception) { $this->assertArrayHasKey('request_key', $exception->errors()); }
        }
        $reason = 'Motivo original do treinamento interno';
        $cancelKey = (string) Str::uuid();
        $service->cancel($this->tenant, $first->id, $this->user->id, $reason, $cancelKey);
        $eventCount = \App\Models\FiscalSimulationEvent::count();
        $this->assertSame('cancelled', $service->cancel($this->tenant, $first->id, $this->user->id, $reason, $cancelKey)->state);
        $this->assertSame('cancelled', $service->cancel($this->tenant, $first->id, $this->user->id, $reason, (string) Str::uuid())->state);
        $this->assertSame($eventCount, \App\Models\FiscalSimulationEvent::count());
        try {
            $service->cancel($this->tenant, $first->id, $this->user->id, 'Outro motivo incompatível com o anterior', (string) Str::uuid());
            $this->fail('Cancelamento repetido não pode trocar o motivo histórico.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('reason', $exception->errors()); }
        $this->sale->update(['status' => 'cancelled']);
        try {
            $service->create($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
            $this->fail('Venda cancelada não pode originar novo exercício.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('sale', $exception->errors()); }
        $this->assertSame(2, \App\Models\FiscalSimulation::count());
        $this->assertSame($eventCount, \App\Models\FiscalSimulationEvent::count());
        Http::assertNothingSent();
    }

    public function test_internal_simulation_exports_are_non_fiscal_and_enforce_admin_tenant_and_integrity(): void
    {
        $this->forbidSimulationTransport();
        $service = app(\App\Fiscal\SimulationFiscal::class);
        $simulation = $service->create($this->tenant, $this->sale->id, $this->user->id, (string) Str::uuid());
        $this->login();
        $base = '/app/a/fiscal/simulations/'.$simulation->id;
        $this->get($base)->assertOk()->assertSee('SIMULAÇÃO INTERNA');
        $receipt = $this->get($base.'/receipt')->assertOk()->assertSee('SEM VALOR FISCAL')->assertSee('NÃO É DANFE')->assertSee('15,03');
        $this->assertStringContainsString('text/html', $receipt->headers->get('Content-Type'));
        $this->assertStringContainsString('private', $receipt->headers->get('Cache-Control'));
        $download = $this->get($base.'/download')->assertOk()->assertHeader('Content-Type', 'application/json')->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('schema', 'VendraSimulation')->assertJsonPath('snapshot.environment', 'internal');
        $this->assertStringContainsString('.json', $download->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('<NFe', $download->getContent());
        foreach (['access_key', 'protocol', 'number'] as $field) { $this->assertArrayNotHasKey($field, $download->json()); }
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/simulate', ['request_key' => (string) Str::uuid(), 'scenario' => 'accepted',
            'tenant_id' => 999, 'state' => 'authorized', 'access_key' => str_repeat('1', 44), 'protocol' => '135260000000001'])
            ->assertSessionHasErrors(['tenant_id', 'state', 'access_key', 'protocol']);
        app(TenantConnection::class)->connect($this->tenant);
        $this->user->update(['role' => 'operator']);
        foreach (['/app/a/fiscal/simulations', $base, $base.'/download', $base.'/receipt'] as $url) { $this->get($url)->assertForbidden(); }
        foreach (['/app/a/sales/'.$this->sale->id.'/fiscal/simulate', $base.'/consult', $base.'/cancel'] as $url) {
            $this->post($url, ['request_key' => (string) Str::uuid(), 'scenario' => 'accepted', 'reason' => 'Motivo de treinamento interno'])->assertForbidden();
        }
        app(TenantConnection::class)->connect($this->tenant);
        $this->user->update(['role' => 'admin']);
        $other = Tenant::where('slug', 'b')->firstOrFail();
        app(TenantConnection::class)->connect($other);
        $userB = User::forceCreate(['id' => $this->user->id, 'name' => 'Admin outra empresa', 'email' => 'simulation-b@example.test', 'password' => 'simulation-password-test', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $other->id, 'user_id' => $userB->id, 'auth_version' => 0]);
        $this->get('/app/b/fiscal/simulations/'.$simulation->id)->assertNotFound();
        $this->post('/app/b/fiscal/simulations/'.$simulation->id.'/consult', ['request_key' => (string) Str::uuid()])->assertNotFound();
        app(TenantConnection::class)->connect($other);
        try {
            $service->create($this->tenant, $this->sale->id, $userB->id, (string) Str::uuid());
            $this->fail('Tenant recebido não pode divergir da conexão ativa.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) { $this->assertSame(403, $exception->getStatusCode()); }
        $this->assertSame(0, \App\Models\FiscalSimulation::count());
        app(TenantConnection::class)->connect($this->tenant);
        DB::connection('tenant')->table('fiscal_simulations')->where('id', $simulation->id)->update(['snapshot_sha256' => str_repeat('0', 64)]);
        $this->login();
        $this->get($base.'/download')->assertSessionHasErrors('simulation');
        Http::assertNothingSent();
    }

    public function test_internal_simulation_draft_is_partial_private_and_preserves_active_configuration(): void
    {
        $this->forbidSimulationTransport();
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Empresa de treinamento', 'document' => '11.222.333/0001-81']);
        $this->login();
        $payload = ['intent' => 'draft', 'environment' => 'homologation', 'series' => '2', 'natureza_operacao' => 'VENDA RASCUNHO'];
        $before = $this->simulationProtectedFacts();
        $this->post('/app/a/business/fiscal', $payload)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $draft = FiscalSetting::findOrFail(1)->configuration_draft;
        $this->assertSame('11222333000181', $draft['document']);
        $this->assertSame('VENDA RASCUNHO', $draft['values']['natureza_operacao']);
        $this->assertSame($before, $this->simulationProtectedFacts());
        $this->get('/app/a/business')->assertOk()->assertViewHas('fiscalDraftPresent', true)
            ->assertViewHas('fiscalDraft', fn ($values) => $values['natureza_operacao'] === 'VENDA RASCUNHO');
        app(TenantConnection::class)->connect($this->tenant);
        $issuer = \App\Models\FiscalIssuer::create(['document' => '11222333000181', 'environment' => 'homologation', 'provider' => 'sefaz_direct', 'enabled' => true, 'validated_configuration' => ['series' => 1]]);
        FiscalCredential::create(['issuer_id' => $issuer->id, 'secrets' => ['csc' => 'SYNTHETIC-EXISTING'], 'validated_hash' => str_repeat('a', 64),
            'certificate_fingerprint' => str_repeat('b', 64), 'certificate_document' => '11222333000181',
            'valid_from' => now()->subDay(), 'valid_until' => now()->addMonth()]);
        DB::connection('tenant')->table('fiscal_sequences')->insert(['issuer_id' => $issuer->id, 'model' => 65, 'series' => 1, 'next_number' => 42]);
        $active = $this->simulationProtectedFacts();
        $this->post('/app/a/business/fiscal', array_replace($payload, ['series' => '9']))->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame($active, $this->simulationProtectedFacts());
        BusinessProfile::findOrFail(1)->update(['document' => '04.252.011/0001-10']);
        $this->get('/app/a/business')->assertOk()->assertViewHas('fiscalDraftPresent', false)->assertViewHas('fiscalDraft', []);
        app(TenantConnection::class)->connect($this->tenant);
        $this->user->update(['role' => 'operator']);
        $this->post('/app/a/business/fiscal', $payload)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_http_emission_accepts_browser_checkbox_strings_and_queues_without_transmission(): void
    {
        $this->configuredFiscal();
        $this->login();
        $this->post('/app/a/sales/'.$this->sale->id.'/fiscal/emit', ['consumer_final' => '1', 'not_taxpayer' => '1',
            'homologation_consent' => '1', 'presence' => '1', 'destination_state' => 'SP', 'pix_mode' => 'dynamic'])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->tenant);
        $document = FiscalDocument::firstOrFail();
        $this->assertSame('pending', $document->state);
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->assertSame([], $this->transportCalls);
    }

    public function test_direct_void_is_explicitly_blocked_without_queue_or_number_reservation(): void
    {
        $this->configuredFiscal();
        $this->login();
        $this->post('/app/a/fiscal/voids', ['last_number' => 3, 'reason' => 'Inutilização sintética sem valor fiscal',
            'request_key' => (string) Str::uuid(), 'homologation_consent' => true])->assertSessionHasErrors('fiscal');
        app(TenantConnection::class)->connect($this->tenant);
        $this->assertSame(0, DB::connection('tenant')->table('fiscal_voids')->count());
        $this->assertSame(0, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        $this->assertSame([], $this->transportCalls);
    }

    public function test_real_mysql_concurrency_allocates_unique_numbers_and_replays_same_sale(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Concorrência fiscal exige pcntl.');
        }
        $this->configuredFiscal();
        $second = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true, 'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01']]], $this->user->id);
        $ids = [$this->sale->id, $second->id];
        $operation = fn (int $worker) => app(QueueNfce::class)->enqueue($this->tenant, $ids[$worker], $this->user->id, $this->operation())->id;
        $results = $this->raceEmission($operation);
        $this->assertNotSame($results[0], $results[1]);
        $this->assertSame([1, 2], FiscalDocument::orderBy('number')->pluck('number')->all());
        $this->assertSame(3, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        $results = $this->raceEmission(fn () => app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation())->id);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_outbox')->count());
    }

    private function raceEmission(\Closure $operation): array
    {
        DB::purge('tenant');
        DB::purge('central');
        $sockets = [];
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new \RuntimeException('Não foi possível criar worker fiscal.');
            }
            if ($pid === 0) {
                fclose($pair[0]);
                try {
                    app(TenantConnection::class)->connect($this->tenant);
                    fwrite($pair[1], 'R');
                    fread($pair[1], 1);
                    $result = ['id' => $operation($worker)];
                } catch (\Throwable $e) {
                    $result = ['error' => $e::class];
                } finally {
                    app(TenantConnection::class)->disconnect();
                }
                fwrite($pair[1], json_encode($result));
                fclose($pair[1]);
                exit(0);
            }
            fclose($pair[1]);
            stream_set_timeout($pair[0], 30);
            $sockets[] = $pair[0];
            $children[] = $pid;
        }
        foreach ($sockets as $socket) {
            $this->assertSame('R', fread($socket, 1));
        }
        foreach ($sockets as $socket) {
            fwrite($socket, 'S');
        }
        $ids = [];
        foreach ($sockets as $socket) {
            $result = json_decode(stream_get_contents($socket), true, flags: JSON_THROW_ON_ERROR);
            fclose($socket);
            $this->assertArrayNotHasKey('error', $result, $result['error'] ?? '');
            $ids[] = $result['id'];
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        DB::purge('central');
        DB::purge('tenant');
        app(TenantConnection::class)->connect($this->tenant);

        return $ids;
    }

    public function test_direct_not_found_consultation_never_automatically_resubmits_or_renumbers(): void
    {
        $this->configuredFiscal();
        $queue = app(QueueNfce::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $id = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($id);
        $this->transport->shouldReceive('consult')->andReturnUsing(function (string $key) {
            $this->transportCalls[] = 'consult';
            return ['request' => '', 'response' => '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe"><tpAmb>2</tpAmb><cStat>217</cStat><xMotivo>SIMULACAO NAO CONSTA</xMotivo><chNFe>'.$key.'</chNFe></retConsSitNFe>'];
        });
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->runFiscalWorker($id);
        $this->assertSame('uncertain', $doc->fresh()->state);
        $this->assertSame(['authorize', 'consult'], $this->transportCalls);
        $this->assertSame(1, FiscalDocument::count());
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
    }

    public function test_discount_and_commission_snapshots_are_preserved_in_fiscal_payload(): void
    {
        $this->configuredFiscal();
        $this->product->update(['discount_enabled' => true, 'max_discount_percent' => '20', 'commission_percent' => '5']);
        $sale = app(Checkout::class)->complete(['request_key' => (string) Str::uuid(), 'payment_method' => 'pix', 'paid' => true, 'items' => [['kind' => 'product', 'id' => $this->product->id, 'quantity' => '1', 'unit_price' => '10.01', 'discount_percent' => '10']]], $this->user->id);
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $sale->id, $this->user->id, $this->operation());
        $p = $doc->snapshot['payload'];
        $this->assertSame('10.01', $p['valor_produtos']);
        $this->assertSame('1.00', $p['valor_desconto']);
        $this->assertSame('9.01', $p['valor_total']);
        $this->assertSame('10.01', $p['items'][0]['valor_bruto']);
        $this->assertSame('1.00', $p['items'][0]['valor_desconto']);
        $this->assertSame('9.01', $p['formas_pagamento'][0]['valor_pagamento']);
        $this->assertSame('0.45', $sale->fresh()->commission_total);
    }

    public function test_commercial_cancellation_before_first_send_blocks_transmission(): void
    {
        $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $this->sale->update(['status' => 'cancelled']);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->value('id'));
        $this->assertSame('uncertain', $doc->fresh()->state);
        $this->assertStringContainsString('commercial_sale_cancelled', $doc->fresh()->operator_message);
        Http::assertNothingSent();
    }
}
