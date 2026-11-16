<?php

namespace Tests\Feature;

use App\Fiscal\Snapshot;
use App\Models\Tenant;
use App\Platform\PlatformAdmin;
use App\Platform\PlatformAudit;
use App\Platform\ProcessProvisionRequest;
use App\Platform\RequestCompanyProvision;
use App\Platform\TenantStorageProvisioner;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    private static bool $schemaReady = false;

    private PlatformAdmin $admin;

    private Tenant $company;

    protected function setUp(): void
    {
        parent::setUp();
        if (! env('VENDRA_TEST_PASSWORD')) {
            $this->markTestSkipped('Configure o usuário e o banco central dedicados aos testes.');
        }
        config(['database.default' => 'central', 'database.connections.central' => [
            'driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'vendra_test_central', 'username' => 'vendra_test',
            'password' => env('VENDRA_TEST_PASSWORD'), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
        DB::purge('central');
        $this->guardCentralDatabase();
        if (! self::$schemaReady) {
            Artisan::call('migrate:fresh', ['--database' => 'central', '--force' => true]);
            self::$schemaReady = true;
        }
        $connection = DB::connection('central');
        $connection->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($connection->select('SHOW TABLES') as $row) {
                $table = array_values((array) $row)[0];
                if ($table === 'migrations') {
                    continue;
                }
                if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                    throw new \RuntimeException('Tabela inesperada no banco central de teste.');
                }
                $connection->delete('DELETE FROM `'.$table.'`');
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->admin = PlatformAdmin::create(['name' => 'Administrador central QA', 'email' => 'central@example.test', 'password' => Hash::make('test-central-password-123'), 'active' => true, 'auth_version' => 0]);
        $this->company = Tenant::create(['slug' => 'platform-a', 'name' => 'Empresa plataforma A', 'active' => true, 'db_host' => '127.0.0.1', 'db_name' => 'vendra_t_test_a', 'db_username' => 'vendra_test', 'db_password' => 'TEST-NEVER-LOG-DB-PASSWORD']);
    }

    private function guardCentralDatabase(): void
    {
        $connection = DB::connection('central');
        if ($connection->getDatabaseName() !== 'vendra_test_central' || $connection->getConfig('username') !== 'vendra_test') {
            throw new \RuntimeException('DDL/limpeza recusados fora do banco central de teste.');
        }
        if ($connection->selectOne('SELECT DATABASE() AS current_database')->current_database !== 'vendra_test_central') {
            throw new \RuntimeException('Banco ativo não corresponde ao banco central de teste.');
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

    private function centralSession(): void
    {
        $this->withSession(['platform_admin_id' => $this->admin->id, 'platform_auth_version' => $this->admin->auth_version]);
    }

    public function test_platform_login_is_distinct_from_customer_admin_session_and_logs_out_with_audit(): void
    {
        $this->withSession(['tenant_id' => $this->company->id, 'user_id' => $this->admin->id, 'auth_version' => 0, 'role' => 'admin']);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->get('/vexo-admin/login')->assertOk();
        $this->post('/vexo-admin/login', ['email' => $this->admin->email, 'password' => 'test-central-password-123'])
            ->assertRedirect('/vexo-admin')->assertSessionHas('platform_admin_id', $this->admin->id)->assertSessionHas('platform_auth_version', 0);
        $this->get('/vexo-admin')->assertOk();
        $this->assertSame(1, PlatformAudit::where('action', 'platform.login')->where('platform_admin_id', $this->admin->id)->count());
        $this->post('/vexo-admin/logout')->assertRedirect('/vexo-admin/login')->assertSessionMissing('platform_admin_id')->assertSessionMissing('platform_auth_version');
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->assertSame(1, PlatformAudit::where('action', 'platform.logout')->where('platform_admin_id', $this->admin->id)->count());
    }

    public function test_platform_credentials_are_generic_rate_limited_and_session_revocation_is_enforced(): void
    {
        $unknown = $this->postJson('/vexo-admin/login', ['email' => 'unknown@example.test', 'password' => 'incorrect-test-password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $wrong = $this->postJson('/vexo-admin/login', ['email' => $this->admin->email, 'password' => 'incorrect-test-password'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($unknown->json('errors.email'), $wrong->json('errors.email'));
        for ($i = 1; $i < 5; $i++) {
            $this->post('/vexo-admin/login', ['email' => $this->admin->email, 'password' => 'incorrect-test-password'])->assertSessionHasErrors('email')->assertSessionMissing('_old_input.password');
        }
        $limited = $this->postJson('/vexo-admin/login', ['email' => $this->admin->email, 'password' => 'test-central-password-123'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertStringContainsString('Muitas tentativas', $limited->json('errors.email.0'));
        $this->assertSame(0, PlatformAudit::where('action', 'platform.login')->count());
        $this->centralSession();
        $this->admin->update(['auth_version' => 1]);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login')->assertSessionMissing('platform_admin_id');
        $this->centralSession();
        $this->admin->update(['active' => false]);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login')->assertSessionMissing('platform_admin_id');
        $this->post('/vexo-admin/companies/'.$this->company->id.'/status', ['active' => false, 'reason' => 'Tentativa sem sessão central'])->assertRedirect('/vexo-admin/login');
        $this->assertTrue($this->company->fresh()->active);
    }

    public function test_platform_company_status_is_optimistic_even_with_same_second_aba_and_audit_is_immutable(): void
    {
        $this->travelTo($this->company->updated_at);
        try {
            $this->centralSession();
            $before = $this->company->only(['db_host', 'db_name', 'db_username', 'db_password']);
            $this->get('/vexo-admin')->assertOk()->assertDontSee('TEST-NEVER-LOG-DB-PASSWORD', false)->assertViewHas('companies', function ($rows) {
                $record = $rows->first();

                return $record && ! array_key_exists('db_username', $record->getAttributes()) && ! array_key_exists('db_password', $record->getAttributes()) && ! array_key_exists('db_name', $record->getAttributes());
            });
            $url = '/vexo-admin/companies/'.$this->company->id.'/status';
            $initial = ['active' => false, 'expected_version' => 0, 'reason' => 'Suspensão contratual QA'];
            $this->post($url, $initial)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertFalse($this->company->fresh()->active);
            $first = PlatformAudit::where('tenant_id', $this->company->id)->sole();
            $this->assertSame($this->admin->id, $first->platform_admin_id);
            $this->assertSame(Snapshot::hash(['active' => ['before' => true, 'after' => false]]), Snapshot::hash($first->changes));
            $this->postJson($url, array_replace($initial, ['active' => true]))->assertUnprocessable()->assertJsonValidationErrors('expected_version');
            $current = array_replace($initial, ['expected_version' => 1]);
            $this->post($url, $current)->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(1, PlatformAudit::where('tenant_id', $this->company->id)->count());
            $this->post($url, array_replace($current, ['active' => true]))->assertRedirect()->assertSessionHasNoErrors();
            $this->assertTrue($this->company->fresh()->active);
            $this->postJson($url, $initial)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
            $this->assertSame(2, PlatformAudit::where('tenant_id', $this->company->id)->count());
            $this->assertSame($before, $this->company->fresh()->only(['db_host', 'db_name', 'db_username', 'db_password']));
            try {
                $first->update(['reason' => 'Histórico reescrito']);
                $this->fail('Auditoria central não pode ser reescrita.');
            } catch (\LogicException) {
                $this->assertSame('Suspensão contratual QA', $first->fresh()->reason);
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_platform_provision_requests_are_idempotent_pending_and_never_create_tenants_or_flash_credentials(): void
    {
        $this->centralSession();
        $this->get('/vexo-admin/companies/new')->assertOk();
        $payload = ['name' => 'Nova empresa QA', 'slug' => 'new-company', 'admin_name' => 'Responsável QA', 'admin_email' => 'owner@example.test', 'is_demonstration' => false, 'request_key' => (string) Str::uuid()];
        $this->post('/vexo-admin/companies', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/vexo-admin/companies', $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, Tenant::count());
        $this->assertSame(1, DB::connection('central')->table('platform_provision_requests')->count());
        $request = DB::connection('central')->table('platform_provision_requests')->first();
        $this->assertSame('pending', $request->status);
        $this->get('/vexo-admin/requests/'.$request->id)->assertOk()->assertDontSee('TEST-NEVER-LOG-DB-PASSWORD', false);
        $this->postJson('/vexo-admin/companies', array_replace($payload, ['admin_email' => 'different@example.test']))->assertUnprocessable();
        $this->postJson('/vexo-admin/companies', array_replace($payload, ['request_key' => (string) Str::uuid()]))->assertUnprocessable();
        $this->from('/vexo-admin/companies/new')->post('/vexo-admin/companies', array_replace($payload, ['slug' => 'other-company', 'request_key' => (string) Str::uuid(), 'db_password' => 'SYNTHETIC-DB-SECRET', 'admin_password' => 'SYNTHETIC-ADMIN-SECRET', 'db_name' => 'arbitrary_database']))
            ->assertSessionHasErrors(['db_password', 'admin_password', 'db_name'])->assertSessionMissing('_old_input.db_password')->assertSessionMissing('_old_input.admin_password');
        $this->assertSame(1, Tenant::count());
        $this->assertSame(1, DB::connection('central')->table('platform_provision_requests')->count());
        $this->get('/vexo-admin/companies/'.$this->company->id)->assertOk()->assertDontSee('TEST-NEVER-LOG-DB-PASSWORD', false)->assertDontSee('vendra_test', false);
        $this->getJson('/vexo-admin?q[]=invalid')->assertUnprocessable()->assertJsonValidationErrors('q');
    }

    public function test_platform_cli_worker_provisions_only_new_inactive_tenant_then_activates_once_with_fake_storage(): void
    {
        config(['tenancy.shared.enabled' => true, 'tenancy.shared.host' => '127.0.0.1', 'tenancy.shared.database' => 'vendra_shared_qa']);
        $job = app(RequestCompanyProvision::class)->request(['name' => 'Cliente novo compartilhado', 'slug' => 'worker-success', 'admin_name' => 'Responsável novo', 'admin_email' => 'new-owner@example.test', 'is_demonstration' => true, 'request_key' => (string) Str::uuid()], $this->admin->id, $this->admin->auth_version);
        $original = Snapshot::hash($this->company->fresh()->getRawOriginal());
        $storage = new class implements TenantStorageProvisioner
        {
            public int $calls = 0;

            public array $captured = [];

            public function provisionNew(Tenant $tenant, array $attributes, #[\SensitiveParameter] string $password): void
            {
                $this->calls++;
                $this->captured = ['id' => $tenant->id, 'active' => $tenant->active, 'provisioned' => $tenant->provisioned_at, 'mode' => $tenant->storage_mode, 'username' => $tenant->db_username, 'credential_length' => strlen($tenant->db_password), 'encrypted' => ! hash_equals((string) $tenant->getRawOriginal('db_password'), $tenant->db_password), 'attributes' => $attributes, 'password_matched' => hash_equals('Initial-test-secret-1234', $password)];
            }

            public function rollbackNew(Tenant $tenant): void
            {
                throw new \LogicException('Successful fixture must not invoke rollback.');
            }
        };
        $worker = new ProcessProvisionRequest($storage);
        $completed = $worker->process($job->id, 'Initial-test-secret-1234');
        $this->assertSame('completed', $completed->status);
        $this->assertSame(1, $storage->calls);
        $this->assertFalse($storage->captured['active']);
        $this->assertNull($storage->captured['provisioned']);
        $this->assertSame('shared', $storage->captured['mode']);
        $this->assertSame(48, $storage->captured['credential_length']);
        $this->assertTrue($storage->captured['encrypted']);
        $this->assertTrue($storage->captured['password_matched']);
        $this->assertMatchesRegularExpression('/^vxs_'.str_pad(dechex($completed->tenant_id), 16, '0', STR_PAD_LEFT).'_[a-f0-9]{8}$/D', $storage->captured['username']);
        $this->assertSame(['name' => 'Responsável novo', 'email' => 'new-owner@example.test', 'must_change_password' => true], $storage->captured['attributes']);
        $created = Tenant::findOrFail($completed->tenant_id);
        $this->assertTrue($created->active);
        $this->assertTrue($created->is_demonstration);
        $this->assertTrue($created->fiscal_demonstration);
        $this->assertNotNull($created->provisioned_at);
        $this->assertSame(1, $created->platform_version);
        $this->assertNull($completed->active_slug);
        $this->assertSame(2, Tenant::count());
        $this->assertSame($completed->id, $worker->process($job->id, 'Initial-test-secret-1234')->id);
        $this->assertSame(1, $storage->calls);
        $this->assertSame(1, PlatformAudit::where('action', 'company.provision_completed')->count());
        $this->assertSame($original, Snapshot::hash($this->company->fresh()->getRawOriginal()));
        try {
            app(RequestCompanyProvision::class)->request(['name' => 'Não sobrescrever', 'slug' => $this->company->slug, 'admin_name' => 'Outro responsável', 'admin_email' => 'other@example.test', 'is_demonstration' => false, 'request_key' => (string) Str::uuid()], $this->admin->id, $this->admin->auth_version);
            $this->fail('Provisionamento não pode reutilizar empresa existente.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('slug', $e->errors());
        }
    }

    public function test_platform_cli_worker_fails_closed_sanitizes_adapter_errors_and_never_retries_failed_namespace(): void
    {
        $job = app(RequestCompanyProvision::class)->request(['name' => 'Falha isolada QA', 'slug' => 'worker-failure', 'admin_name' => 'Responsável falha', 'admin_email' => 'failed-owner@example.test', 'is_demonstration' => false, 'request_key' => (string) Str::uuid()], $this->admin->id, $this->admin->auth_version);
        $storage = new class implements TenantStorageProvisioner
        {
            public int $calls = 0;

            public array $rollbackIds = [];

            public function provisionNew(Tenant $tenant, array $attributes, #[\SensitiveParameter] string $password): void
            {
                $this->calls++;
                throw new \RuntimeException('SQL-password=SYNTHETIC-ADAPTER-SECRET');
            }

            public function rollbackNew(Tenant $tenant): void
            {
                $this->rollbackIds[] = $tenant->id;
            }
        };
        $worker = new ProcessProvisionRequest($storage);
        config(['tenancy.shared.enabled' => false]);
        try {
            $worker->process($job->id, 'Initial-test-secret-1234');
            $this->fail('Feature desabilitada não pode implantar namespace.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('storage', $e->errors());
        }
        $this->assertSame('pending', $job->fresh()->status);
        $this->assertSame(0, $storage->calls);
        $this->assertSame(1, Tenant::count());
        config(['tenancy.shared.enabled' => true, 'tenancy.shared.host' => '127.0.0.1', 'tenancy.shared.database' => 'vendra_shared_qa']);
        $failed = $worker->process($job->id, 'Initial-test-secret-1234');
        $this->assertSame('failed', $failed->status);
        $this->assertSame('provision_failed', $failed->failure_code);
        $this->assertSame([$failed->tenant_id], $storage->rollbackIds);
        $company = Tenant::findOrFail($failed->tenant_id);
        $this->assertFalse($company->active);
        $this->assertNull($company->provisioned_at);
        $this->assertSame(1, $company->platform_version);
        $this->assertStringNotContainsString('SYNTHETIC-ADAPTER-SECRET', json_encode([$failed->toArray(), PlatformAudit::all()->toArray()], JSON_THROW_ON_ERROR));
        try {
            $worker->process($job->id, 'Initial-test-secret-1234');
            $this->fail('Falha de infraestrutura exige conferência antes de repetir.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request', $e->errors());
        }
        $this->assertSame(1,$storage->calls);
        $this->assertSame([$failed->tenant_id],$storage->rollbackIds);
        $this->centralSession();
        $this->postJson('/vexo-admin/companies/'.$company->id.'/status',['active' => true, 'expected_version' => $company->platform_version, 'reason' => 'Ativação indevida'])->assertUnprocessable();
        $this->assertFalse($company->fresh()->active);
        $this->assertTrue($this->company->fresh()->active);
    }
}
