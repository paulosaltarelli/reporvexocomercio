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
    use \Tests\Support\PlatformSmtpCases;
    use \Tests\Support\LicensingWorkerCases;
    use \Tests\Support\ContractBillingCases;
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
        $this->withSession(['platform_admin_id' => $this->admin->id, 'platform_mfa_at' => time(), 'platform_auth_version' => $this->admin->auth_version]);
    }

    public function test_license_forms_show_terms_and_preserve_empty_module_selection_on_update(): void
    {
        $url = '/vexo-admin/companies/'.$this->company->id.'/license';
        $this->get($url)->assertRedirect('/vexo-admin/login');
        $this->centralSession();
        $this->get($url)->assertOk()->assertSee('Nenhum contrato cadastrado')->assertSee('Novo contrato');
        $this->get($url.'/new')->assertOk()->assertSee('Recursos sujeitos à restrição por atraso')->assertSee('Mensalidade')
            ->assertSee('name="implantation_grace_days" min="0" max="3650" step="1" value="0"', false)
            ->assertSee('name="overdue_grace_days" min="0" max="3650" step="1" value="0"', false);
        $data = ['start_date'=>'2028-05-01', 'end_date'=>'2028-06-30', 'monthly_amount'=>'165.50', 'due_day'=>20, 'restricted_modules'=>['sales']];
        $this->post($url.'/contracts', $data)->assertRedirect($url)->assertSessionHasNoErrors();
        $contract = \App\Platform\Contract::where('tenant_id', $this->company->id)->sole();
        $edit = $url.'/contracts/'.$contract->id.'/edit';
        $this->get($url)->assertOk()->assertSee($edit)->assertSee('165,50')->assertSee('05/2028')->assertSee('06/2028');
        $this->get($edit)->assertOk()->assertSee('value="sales" checked', false);
        unset($data['restricted_modules']);
        $data['implantation_grace_days'] = 3;
        $data['overdue_grace_days'] = 5;
        $this->post($url.'/contracts/'.$contract->id, $data + ['expected_version'=>$contract->version])
            ->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertSame([], $contract->fresh()->restricted_modules);
        $this->get($url)->assertOk()->assertSee('3 dias')->assertSee('5 dias');
        $this->get($edit)->assertOk()->assertSee('name="overdue_grace_days" min="0" max="3650" step="1" value="5"', false);
    }

    public function test_global_admin_management_is_central_only_preserves_last_admin_and_revokes_sessions(): void
    {
        $this->get('/vexo-admin/admins')->assertRedirect('/vexo-admin/login');
        $this->withSession(['platform_admin_id'=>$this->admin->id,'platform_mfa_at'=>time(),'platform_auth_version'=>$this->admin->auth_version]);
        $this->get('/vexo-admin/admins')->assertOk()->assertSee('Administradores Vexo');
        $base=['name'=>'Administrador','email'=>$this->admin->email,'active'=>0,'expected_version'=>$this->admin->auth_version];
        $this->post('/vexo-admin/admins/'.$this->admin->id,$base)->assertSessionHasErrors('active');
        $this->post('/vexo-admin/admins',['name'=>'Segundo DBA','email'=>'SECOND@example.test','active'=>1,'password'=>'test-global-password-9876','password_confirmation'=>'test-global-password-9876'])->assertRedirect('/vexo-admin/admins')->assertSessionHasNoErrors();
        $other=PlatformAdmin::where('email','second@example.test')->firstOrFail();$this->assertTrue(Hash::check('test-global-password-9876',$other->password));
        $before=$other->auth_version;
        $this->post('/vexo-admin/admins/'.$other->id,['name'=>$other->name,'email'=>$other->email,'active'=>0,'expected_version'=>$before])->assertRedirect('/vexo-admin/admins')->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->active);$this->assertSame($before+1,$other->fresh()->auth_version);
        $this->post('/vexo-admin/admins/'.$other->id,['name'=>'Stale','email'=>$other->email,'active'=>1,'expected_version'=>$before])->assertSessionHasErrors('expected_version');
        $this->assertStringNotContainsString('test-global-password-9876',PlatformAudit::all()->toJson());
        $this->post('/vexo-admin/admins',['name'=>'Bad','email'=>'bad@example.test','active'=>1,'password'=>'short','password_confirmation'=>'short'])->assertSessionHasErrors('password')->assertSessionMissing('_old_input.password');
    }
    public function test_central_shows_company_login_and_setup_urls_without_starting_support_on_get(): void
    {
        $this->withSession(['platform_admin_id'=>$this->admin->id,'platform_mfa_at'=>time(),'platform_auth_version'=>$this->admin->auth_version]);
        $this->get('/vexo-admin')->assertOk()->assertSee('/app/'.$this->company->slug.'/login',false)->assertSessionMissing('platform_support');
        $this->get('/vexo-admin/companies/'.$this->company->id)->assertOk()->assertSee('/app/'.$this->company->slug.'/setup',false)->assertSee('Acessar loja como DBA')->assertSessionMissing('platform_support');
        $this->get('/vexo-admin/companies/'.$this->company->id.'/support')->assertStatus(405);
    }

    public function test_company_hosts_isolate_slug_and_central_routes_and_keep_legacy_hosts(): void
    {
        config(['tenancy.company_domain' => 'vendra.test', 'tenancy.legacy_hosts' => 'painel.vendra.test,127.0.0.1']);
        $this->get('https://platform-a.vendra.test/')->assertRedirect('https://platform-a.vendra.test/app/platform-a/login');
        $this->get('https://platform-a.vendra.test/app/platform-b/login')->assertNotFound();
        $this->get('https://platform-a.vendra.test/vexo-admin/login')->assertNotFound();
        $this->get('https://empresa-ausente.vendra.test/')->assertNotFound();
        $this->get('https://desconhecido.test/vexo-admin/login')->assertNotFound();
        $this->get('https://painel.vendra.test/vexo-admin/login')->assertOk();
        $this->get('https://127.0.0.1/vexo-admin/login')->assertOk();
        $this->centralSession();
        $this->postJson('https://painel.vendra.test/vexo-admin/companies', [
            'name'=>'Reservada', 'slug'=>'painel', 'admin_name'=>'Operador', 'admin_email'=>'owner@example.test',
            'is_demonstration'=>false, 'request_key'=>(string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_demo_host_is_optional_isolates_vexo_and_reserves_its_label_without_wildcard(): void
    {
        $alias = 'lojavendra.vexogestao.com.br';
        $this->company->update(['slug' => 'vexo']);
        config(['tenancy.company_domain' => '', 'tenancy.demo_host' => '']);
        $this->assertStringNotContainsString($alias, \App\Tenancy\CompanyHosts::url('vexo'));
        $this->centralSession();
        $this->postJson('/vexo-admin/companies', [
            'name'=>'Nome reservado', 'slug'=>'lojavendra', 'admin_name'=>'Operador', 'admin_email'=>'owner@example.test',
            'is_demonstration'=>false, 'request_key'=>(string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
        config(['tenancy.demo_host' => $alias]);
        foreach (['', 'vexogestao.com.br'] as $domain) {
            config(['tenancy.company_domain' => $domain]);
            $login = 'https://'.$alias.'/app/vexo/login';
            $this->assertSame($login, \App\Tenancy\CompanyHosts::url('vexo'));
            $this->assertSame('https://'.$alias.'/app/vexo/setup', \App\Tenancy\CompanyHosts::url('vexo', 'setup'));
            $this->get('https://'.$alias.'/')->assertRedirect($login);
            $this->get('https://'.$alias.'/vexo-admin/login')->assertNotFound();
            $this->get('https://'.$alias.'/app/outra/login')->assertNotFound();
        }
        $this->company->update(['active'=>false]);
        $this->get('https://'.$alias.'/')->assertNotFound();
    }

    public function test_password_only_login_preserves_enrolled_factor_and_reenabling_mfa_requires_fresh_login(): void
    {
        config(['platform.mfa_required'=>false]);
        $this->get('/vexo-admin/login')->assertOk()->assertDontSee('name="mfa_code"',false);
        $code = bin2hex(random_bytes(16));
        $recovery = [hash('sha256', $code)];
        $this->admin->update(['mfa_secret'=>\App\Platform\Totp::newSecret(),'mfa_recovery'=>$recovery]);
        $original = $this->admin->fresh()->getRawOriginal('mfa_secret');
        $data = ['email'=>$this->admin->email,'password'=>'test-central-password-123'];
        $this->post('/vexo-admin/login',$data)->assertRedirect('/vexo-admin')->assertSessionMissing('platform_mfa_at');
        $this->get('/vexo-admin')->assertOk();
        $this->get('/vexo-admin/companies/'.$this->company->id)->assertOk()->assertDontSee('name="mfa_code"',false);
        $this->post('/vexo-admin/logout');
        $this->post('/vexo-admin/login',$data + ['mfa_code'=>$code])->assertRedirect('/vexo-admin')->assertSessionMissing('platform_mfa_at');
        $this->assertSame($original,$this->admin->fresh()->getRawOriginal('mfa_secret'));
        $this->assertSame($recovery,$this->admin->fresh()->mfa_recovery);
        $this->assertSame(2,PlatformAudit::where('action','platform.login')->count());
        config(['platform.mfa_required'=>true]);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->get('/vexo-admin/login')->assertOk()->assertSee('name="mfa_code"',false);
        $this->post('/vexo-admin/login',$data)->assertSessionHasErrors('email');
        $this->post('/vexo-admin/login',$data + ['mfa_code'=>$code])->assertRedirect('/vexo-admin')->assertSessionHas('platform_mfa_at');
    }

    public function test_platform_login_is_distinct_from_customer_admin_session_and_logs_out_with_audit(): void
    {
        config(['platform.mfa_required'=>true]);
        $this->withSession(['tenant_id' => $this->company->id, 'user_id' => $this->admin->id, 'auth_version' => 0, 'role' => 'admin']);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->get('/vexo-admin/login')->assertOk();
        $login = ['email' => $this->admin->email, 'password' => 'test-central-password-123'];
        $this->post('/vexo-admin/login', $login)->assertSessionHasErrors('email')->assertSessionMissing('platform_admin_id');
        $secret = \App\Platform\Totp::newSecret();
        $this->admin->update(['mfa_secret' => $secret]);
        $code = \App\Platform\Totp::code($secret, intdiv(time(), 30));
        $this->post('/vexo-admin/login', $login + ['mfa_code' => $code])
            ->assertRedirect('/vexo-admin')->assertSessionHas('platform_admin_id', $this->admin->id)->assertSessionHas('platform_auth_version', 0);
        $this->get('/vexo-admin')->assertOk();
        $this->assertSame(1, PlatformAudit::where('action', 'platform.login')->where('platform_admin_id', $this->admin->id)->count());
        $this->post('/vexo-admin/logout')->assertRedirect('/vexo-admin/login')->assertSessionMissing('platform_admin_id')->assertSessionMissing('platform_auth_version');
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->assertSame(1, PlatformAudit::where('action', 'platform.logout')->where('platform_admin_id', $this->admin->id)->count());
    }

    public function test_recovery_code_is_single_use_and_password_only_sessions_are_rejected(): void
    {
        config(['platform.mfa_required'=>true]);
        $this->withSession(['platform_admin_id' => $this->admin->id, 'platform_auth_version' => 0]);
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $code = bin2hex(random_bytes(16));
        $this->admin->update(['mfa_secret' => \App\Platform\Totp::newSecret(), 'mfa_recovery' => [hash('sha256', $code)]]);
        $form = ['email' => $this->admin->email, 'password' => 'test-central-password-123', 'mfa_code' => $code];
        $this->post('/vexo-admin/login', $form)->assertRedirect('/vexo-admin')->assertSessionHas('platform_mfa_at');
        $this->post('/vexo-admin/logout')->assertRedirect('/vexo-admin/login');
        $this->post('/vexo-admin/login', $form)->assertSessionHasErrors('email')->assertSessionMissing('_old_input.mfa_code');
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
        $completed = $worker->process($job->id, 'Initial-test-secret-1234', true);
        $this->assertSame('queued', $completed->activation_status);
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

    public function test_company_activation_delivery_retries_and_resend_invalidates_old_code(): void
    {
        config(['tenancy.company_domain' => 'vendra.test', 'mail.default' => 'smtp']);
        $job = app(RequestCompanyProvision::class)->request([
            'name'=>'Nova empresa', 'slug'=>'demo-envio', 'admin_name'=>'Dona da loja', 'admin_email'=>'dona@example.test',
            'is_demonstration'=>false, 'request_key'=>(string) Str::uuid(),
        ], $this->admin->id, 0);
        $this->company->update(['slug'=>'demo-envio']);
        $job->update(['status'=>'completed', 'active_slug'=>null, 'tenant_id'=>$this->company->id, 'activation_status'=>'queued']);
        $service = app(\App\Platform\CompanyActivation::class);
        \Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('teste de indisponibilidade SMTP'));
        try {
            $service->send($job->id);
            $this->fail('A falha SMTP deve manter o pedido para nova tentativa.');
        } catch (\RuntimeException $e) {
            $this->assertSame('teste de indisponibilidade SMTP', $e->getMessage());
        }
        $this->assertSame('queued', $job->fresh()->activation_status);
        $this->assertTrue($job->fresh()->activation_retry_at->isFuture());
        $this->travel(11)->minutes();
        try {
            $code = null;
            \Illuminate\Support\Facades\Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $address) use (&$code) {
                $this->assertStringContainsString('https://demo-envio.vendra.test/app/demo-envio/ativar', $body);
                $message = new \Illuminate\Mail\Message(new \Symfony\Component\Mime\Email());
                $address($message);
                $this->assertSame('dona@example.test', $message->getSymfonyMessage()->getTo()[0]->getAddress());
                $this->assertSame(1, preg_match('/[a-f0-9]{32}$/D', $body, $match));
                $code = $match[0];
            });
            $service->send($job->id);
            $this->assertSame('sent', $job->fresh()->activation_status);
            $this->assertSame(hash('sha256', $code), $job->fresh()->activation_digest);
            $this->centralSession();
            $this->post('/vexo-admin/requests/'.$job->id.'/activation')->assertRedirect();
            $this->assertSame('queued', $job->fresh()->activation_status);
            $this->assertNull($job->fresh()->activation_digest);
        } finally {
            $this->travelBack();
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
