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
use App\Fiscal\SyncFocusCompany;
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
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
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

    private function configuredFiscal(): array
    {
        Http::preventStrayRequests();
        BusinessProfile::updateOrCreate(['id' => 1], ['legal_name' => 'SIMULACAO SEM VALOR FISCAL', 'document' => '11.222.333/0001-81', 'state_registration' => '111111111111', 'tax_regime' => 'simples', 'street' => 'Rua de teste', 'number' => '1', 'district' => 'Teste', 'city' => 'São Paulo', 'state' => 'SP', 'postal_code' => '01001000', 'municipality_code' => '3550308']);
        FiscalSetting::updateOrCreate(['id' => 1], ['provider' => 'focus', 'environment' => 'homologation', 'nfce_accredited' => true, 'operation' => 'presential', 'accountant_name' => 'Contador de teste', 'updated_by' => $this->user->id]);
        $this->product->update(['ncm' => '84713012', 'origin' => '0']);
        $certificate = FiscalFixtures::certificate();
        $issuer = app(CompanyFiscalConfiguration::class)->save($this->tenant, [
            'provider_company_id' => '123', 'series' => 1, 'next_number' => 1, 'crt' => '1', 'natureza_operacao' => 'VENDA TESTE', 'identification_limit' => '10000.00', 'cancellation_minutes' => 30, 'uf_validation_reference' => 'Referência de teste sem validade fiscal', 'csc_required' => false,
        ], ['emission_token' => 'emission-secret-test', 'management_token' => 'management-secret-test', 'certificate_password' => $certificate['password']], $certificate['pfx']);
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $credential->synced_hash = app(CompanyFiscalConfiguration::class)->hash($issuer);
        $credential->save();
        FiscalItemRule::create(['product_id' => $this->product->id, 'parameters' => FiscalFixtures::rule(), 'accountant_reference' => 'Somente simulação de teste', 'validated_by' => $this->user->id]);

        return [$issuer, $certificate];
    }

    private function operation(): array
    {
        return ['consumer_final' => true, 'not_taxpayer' => true, 'destination_state' => 'SP', 'presence' => '1'];
    }

    private function runFiscalWorker(int $id): void
    {
        app(ProcessFiscalOutbox::class)->run($this->tenant->id, $id);
        $this->assertNull(config('database.connections.tenant'));
        app(TenantConnection::class)->connect($this->tenant);
    }

    private function fakeAuthorization(FiscalDocument $doc, array $certificate): void
    {
        $this->resetFiscalHttp();
        $fixture = FiscalFixtures::xml($doc->snapshot['payload'], $certificate);
        Http::fake(function ($request) use ($fixture, $doc) {
            $this->assertSame(0, DB::connection('tenant')->transactionLevel(), 'Chamada externa dentro de transação.');
            if (str_contains($request->url(), '/arquivos/')) {
                return Http::response($fixture['xml'], 200);
            }

            return Http::response(['ref' => $doc->reference, 'cnpj_emitente' => '11222333000181', 'status' => 'autorizado', 'status_sefaz' => '100', 'chave_nfe' => 'NFe'.$fixture['key'], 'caminho_xml_nota_fiscal' => '/arquivos/test.xml'], 201);
        });
    }

    private function resetFiscalHttp(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    public function test_private_certificate_matches_issuer_and_secrets_are_encrypted_and_hidden(): void
    {
        [$issuer,$cert] = $this->configuredFiscal();
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $raw = DB::connection('tenant')->table('fiscal_credentials')->value('secrets');
        $this->assertStringNotContainsString('emission-secret-test', $raw);
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
        $this->assertSame('5.02', $doc->snapshot['services_total']);
        $this->assertCount(1, $doc->snapshot['payload']['items']);
        $this->assertSame('10.01', $doc->snapshot['payload']['formas_pagamento'][0]['valor_pagamento']);
        $this->assertSame(1, DB::connection('tenant')->table('fiscal_outbox')->count());
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        Http::assertNothingSent();
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

    public function test_timeout_is_consulted_without_second_post_or_number(): void
    {
        [, $cert] = $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        Http::fake(fn () => throw new ConnectionException('sensitive response must not escape'));
        $id = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($id);
        $this->assertSame('uncertain', $doc->fresh()->state);
        $this->assertStringNotContainsString('sensitive', $doc->fresh()->operator_message);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker($id);
        $this->assertSame('authorized', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(2, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
    }

    public function test_http_success_without_evidence_and_official_rejection_are_distinct(): void
    {
        $this->configuredFiscal();
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        Http::fake(['*' => Http::response(['ref' => $doc->reference, 'cnpj_emitente' => '11222333000181', 'status' => 'autorizado', 'status_sefaz' => '100'], 200)]);
        $id = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        $this->runFiscalWorker($id);
        $this->assertSame('uncertain', $doc->fresh()->state);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $id)->update(['available_at' => now()->subMinute()]);
        $this->resetFiscalHttp();
        Http::fake(['*' => Http::response(['ref' => $doc->reference, 'cnpj_emitente' => '11222333000181', 'status' => 'erro_autorizacao', 'status_sefaz' => '704'], 200)]);
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
        $doc = app(QueueNfce::class)->enqueue($this->tenant, $sale->id, $this->user->id, $this->operation());
        $this->assertCount(1, $doc->snapshot['payload']['items']);
        $this->assertSame('8.00', $doc->snapshot['services_total']);
    }

    public function test_sync_verifies_company_before_sharing_certificate_and_does_not_enable_production(): void
    {
        [$issuer] = $this->configuredFiscal();
        $this->resetFiscalHttp();
        Http::fake(function ($request) {
            if ($request->method() === 'PUT') {
                $this->assertTrue($request['certificado_especifico']);
                $this->assertArrayNotHasKey('csc_nfce_producao', $request->data());
                $this->assertArrayNotHasKey('habilita_nfce', $request->data());
                $this->assertSame('11222333000181', $request['cnpj']);
                $this->assertFalse($request['enviar_email_homologacao']);
            }

            return Http::response(['id' => 123, 'cnpj' => '11222333000181'], 200);
        });
        app(SyncFocusCompany::class)->sync($issuer);
        $credential = FiscalCredential::where('issuer_id', $issuer->id)->firstOrFail();
        $this->assertSame(app(CompanyFiscalConfiguration::class)->hash($issuer), $credential->synced_hash);
        $this->resetFiscalHttp();
        Http::fake(['*' => Http::response(['id' => 123, 'cnpj' => '00000000000000'], 200)]);
        try {
            app(SyncFocusCompany::class)->sync($issuer);
            $this->fail('Wrong company must fail');
        } catch (FiscalTransportException) {
            Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
        }
    }

    public function test_operator_cannot_upload_certificate_issue_download_cancel_or_void(): void
    {
        $this->configuredFiscal();
        $this->user->update(['role' => 'operator']);
        $this->login();
        $this->post('/app/a/business/fiscal', [])->assertForbidden();
        $this->post('/app/a/business/fiscal/sync', [])->assertForbidden();
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
        $doc->refresh();
        $this->assertSame('authorized', $doc->state, $doc->operator_message ?? '');
        $key = $doc->access_key;
        $stock = $this->product->fresh()->quantity;
        $queue->cancel($this->tenant, $doc->id, 'Cancelamento fiscal de teste sem valor fiscal');
        $this->resetFiscalHttp();
        $cancelled = false;
        $xml = FiscalFixtures::xml($doc->snapshot['payload'], $cert)['xml'];
        $event = '<procEventoNFe xmlns="http://www.portalfiscal.inf.br/nfe"><retEvento><infEvento><tpAmb>2</tpAmb><cStat>135</cStat><chNFe>'.$key.'</chNFe><tpEvento>110111</tpEvento><nProt>135260000000002</nProt></infEvento></retEvento></procEventoNFe>';
        Http::fake(function ($request) use (&$cancelled, $doc, $key, $xml, $event) {
            if ($request->method() === 'DELETE') {
                $cancelled = true;

                return Http::response(['status' => 'cancelado'], 200);
            }
            if (str_contains($request->url(), '/arquivos/cancel')) {
                return Http::response($event, 200);
            }
            if (str_contains($request->url(), '/arquivos/')) {
                return Http::response($xml, 200);
            }

            return Http::response(['ref' => $doc->reference, 'cnpj_emitente' => '11222333000181', 'status' => $cancelled ? 'cancelado' : 'autorizado', 'status_sefaz' => $cancelled ? '135' : '100', 'chave_nfe' => 'NFe'.$key, 'caminho_xml_nota_fiscal' => '/arquivos/test.xml', 'caminho_xml_cancelamento' => '/arquivos/cancel.xml'], 200);
        });
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'cancel')->value('id'));
        $this->assertSame('cancelled', $doc->fresh()->state, $doc->fresh()->operator_message ?? '');
        $this->assertSame('completed', $this->sale->fresh()->status);
        $this->assertSame($stock, $this->product->fresh()->quantity);
        $this->assertStringContainsString('<cStat>135', app(PrivateFiscalFiles::class)->get($this->tenant->id, $doc->id, 'cancel_xml'));
        $queue->consult($this->tenant, $doc->id);
        $this->fakeAuthorization($doc, $cert);
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('operation', 'consult')->value('id'));
        $this->assertSame('cancelled', $doc->fresh()->state);
    }

    public function test_void_reserves_unused_range_idempotently_and_preserves_official_xml(): void
    {
        [$issuer] = $this->configuredFiscal();
        $queue = app(QueueVoid::class);
        $key = (string) Str::uuid();
        $reason = 'Inutilizacao de teste sem valor fiscal';
        $id = $queue->enqueue($this->tenant, $issuer, $this->user->id, 3, $reason, $key);
        $this->assertSame($id, $queue->enqueue($this->tenant, $issuer, $this->user->id, 3, $reason, $key));
        $this->assertSame(4, DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
        $xml = '<procInutNFe xmlns="http://www.portalfiscal.inf.br/nfe"><retInutNFe><infInut><tpAmb>2</tpAmb><cStat>102</cStat><ano>'.now()->format('y').'</ano><CNPJ>11222333000181</CNPJ><mod>65</mod><serie>1</serie><nNFIni>1</nNFIni><nNFFin>3</nNFFin><nProt>135260000000003</nProt></infInut></retInutNFe></procInutNFe>';
        Http::fake(fn ($r) => str_contains($r->url(), '/arquivos/') ? Http::response($xml, 200) : Http::response(['status' => 'autorizado', 'status_sefaz' => '102', 'caminho_xml' => '/arquivos/void.xml'], 200));
        $this->runFiscalWorker(DB::connection('tenant')->table('fiscal_outbox')->where('void_id', $id)->value('id'));
        $void = DB::connection('tenant')->table('fiscal_voids')->find($id);
        $this->assertSame('voided', $void->state);
        $this->assertSame('135260000000003', $void->protocol);
        $this->assertStringNotContainsString('<procInut', $void->encrypted_xml);
        $this->assertSame(0, FiscalDocument::count());
        $this->login();
        $this->get('/app/a/fiscal/voids/'.$id.'/xml')->assertOk()->assertSee('135260000000003');
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

    public function test_explicit_resend_requires_404_and_reuses_frozen_reference_and_number(): void
    {
        [, $cert] = $this->configuredFiscal();
        $queue = app(QueueNfce::class);
        $doc = $queue->enqueue($this->tenant, $this->sale->id, $this->user->id, $this->operation());
        $jobId = DB::connection('tenant')->table('fiscal_outbox')->value('id');
        try {
            $queue->resend($this->tenant, $doc->id, $this->user->id);
            $this->fail('Resend before consultation must fail');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        Http::fake(fn () => throw new ConnectionException('connection lost'));
        $this->runFiscalWorker($jobId);
        DB::connection('tenant')->table('fiscal_outbox')->where('id', $jobId)->update(['available_at' => now()->subMinute()]);
        $this->resetFiscalHttp();
        Http::fake(['*' => Http::response([], 404)]);
        $this->runFiscalWorker($jobId);
        $this->assertSame('reference_not_found', DB::connection('tenant')->table('fiscal_outbox')->value('last_error'));
        $queue->resend($this->tenant, $doc->id, $this->user->id);
        $this->resetFiscalHttp();
        $posted = 0;
        $xml = FiscalFixtures::xml($doc->snapshot['payload'], $cert);
        Http::fake(function ($r) use ($doc, $xml, &$posted) {
            if ($r->method() === 'GET' && str_contains($r->url(), '/v2/nfce/')) {
                return Http::response([], 404);
            }
            if (str_contains($r->url(), '/arquivos/')) {
                return Http::response($xml['xml'],200);
            }
            $posted++;
            $this->assertSame((string) $doc->number,$r['numero']);
            $this->assertStringContainsString($doc->reference,$r->url());

            return Http::response(['ref' => $doc->reference, 'cnpj_emitente' => '11222333000181', 'status' => 'autorizado', 'status_sefaz' => '100', 'chave_nfe' => 'NFe'.$xml['key'], 'caminho_xml_nota_fiscal' => '/arquivos/test.xml'],201);
        });
        $this->runFiscalWorker($jobId);
        $this->assertSame('authorized',$doc->fresh()->state,$doc->fresh()->operator_message ?? '');
        $this->assertSame(1,$posted);
        $this->assertSame(1,FiscalDocument::count());
        $this->assertSame(2,DB::connection('tenant')->table('fiscal_sequences')->value('next_number'));
    }
}
