<?php

namespace Tests\Support;

use App\Models\CompanyOperation;
use App\Models\Seller;
use App\Models\User;
use App\Platform\InstallerInvitation;
use App\Platform\MailAttempt;
use App\Mail\InstallerInvitationMail;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

trait InstallerAccessCases
{
    private function inviteInstaller(string $email): array
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $admin = $this->licensingAdmin();
        $session = ['platform_admin_id' => $admin->id, 'platform_auth_version' => 0, 'platform_mfa_at' => time()];
        $base = "/vexo-admin/companies/{$this->a->id}/accesses";
        $response = $this->withSession($session)->post($base, ['name' => 'Implantação de teste', 'email' => $email]);
        $this->assertLessThan(400, $response->status());
        $response->assertSessionHasNoErrors();
        $token = $this->sentInstallerToken($email);
        $response->assertDontSee($token);
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        return [InstallerInvitation::where('tenant_id', $this->a->id)->where('email', $email)->sole(), $token, $session];
    }

    private function sentInstallerToken(string $email): string
    {
        $message = Mail::sent(InstallerInvitationMail::class)->last();
        $this->assertNotNull($message);
        $this->assertTrue($message->hasTo($email));
        $this->assertCount(1, $message->to);
        $this->assertEmpty($message->cc);
        $this->assertEmpty($message->bcc);
        $body = html_entity_decode($message->render());
        $this->assertStringContainsString('/app/a/convite-implantacao', $body);
        $this->assertSame(1, preg_match('/#token=([a-f0-9]{64})/', $body, $token));
        return $token[1];
    }

    public function test_platform_installer_invitation_is_scoped_expires_rotates_and_is_accepted_only_once(): void
    {
        config(['platform.mfa_required'=>true]);
        $a = "/vexo-admin/companies/{$this->a->id}/accesses";
        $b = "/vexo-admin/companies/{$this->b->id}/accesses";
        $email = 'implantador-a@example.test';
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0])
            ->get($a)->assertRedirect('/vexo-admin/login');
        $this->flushSession();
        [$invitation, $original, $central] = $this->inviteInstaller($email);
        $this->assertSame('pending', $invitation->status);
        $this->assertNull($invitation->user_id);
        $this->assertSame(hash('sha256', $original), $invitation->token_digest);
        $this->get($a)->assertOk()->assertSee($email)->assertDontSee($original);
        $this->get($b)->assertOk()->assertDontSee($email)->assertDontSee($original);
        $this->post("$b/{$invitation->id}/revoke", ['expected_version' => $invitation->version])->assertNotFound();
        $this->flushSession();
        $accept = '/app/a/convite-implantacao';
        $payload = ['email' => $email, 'password' => 'Senha escolhida de QA 123', 'password_confirmation' => 'Senha escolhida de QA 123', 'token' => $original];
        $this->get($accept)->assertOk()->assertDontSee($original)
            ->assertSee('aria-controls="invitation-password"', false)->assertSee('aria-controls="invitation-confirm"', false)
            ->assertDontSee('aria-controls="invitation-code"', false);
        $this->get('/app/a/login')->assertOk()->assertSee('aria-controls="password"', false);
        $this->postJson('/app/b/convite-implantacao', $payload)->assertUnprocessable();
        $this->postJson($accept, array_replace($payload, ['email' => 'outra@example.test']))->assertUnprocessable();
        $this->travelTo($invitation->expires_at->addMinute());
        $this->postJson($accept, $payload)->assertUnprocessable();
        $this->travelBack();
        $this->withSession(array_replace($central, ['platform_mfa_at' => time() - 301]))
            ->post("$a/{$invitation->id}/resend", ['expected_version' => $invitation->version])->assertSessionHasErrors('mfa_code');
        $this->withSession($central)->post("$a/{$invitation->id}/resend", ['expected_version' => $invitation->fresh()->version])
            ->assertRedirect()->assertSessionHasNoErrors();
        $newToken = $this->sentInstallerToken($email);
        $this->assertNotSame($original, $newToken);
        $this->flushSession();
        $this->postJson($accept, $payload)->assertUnprocessable();
        $payload['token'] = $newToken;
        $results = $this->race(null, function () use ($accept, $payload) {
            return $this->postJson($accept, $payload)->status();
        });
        sort($results);
        $this->assertLessThan(400, $results[0]);
        $this->assertSame(422, $results[1]);
        $this->postJson($accept, $payload)->assertUnprocessable();
        app(TenantConnection::class)->connect($this->a);
        $installer = User::where('email', $email)->sole();
        $this->assertSame('installer', $installer->role);
        $this->assertFalse($installer->must_change_password);
        $this->assertTrue(Hash::check($payload['password'], $installer->password));
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->post('/app/a/login', ['email' => $email, 'password' => $payload['password']])->assertRedirect('/app/a/setup');
        $this->withSession($central)->post("$a/{$invitation->id}/revoke", ['expected_version' => $invitation->fresh()->version])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/a/setup')->assertRedirect('/app/a/login');
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse($installer->fresh()->active);
        $this->assertTrue($this->user->fresh()->active);
        app(TenantConnection::class)->connect($this->b);
        $this->assertFalse(User::where('email', $email)->exists());
    }

    public function test_concurrent_resend_and_accept_leave_one_consistent_installer_invitation(): void
    {
        $email = 'convite-concorrente@example.test';
        [$invitation, $token, $central] = $this->inviteInstaller($email);
        $accept = '/app/a/convite-implantacao';
        $password = 'Senha de implantação QA 123';
        $payload = ['email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
        $attempts = $this->race(null, function () use ($accept, $payload) {
            $this->flushSession();
            return $this->postJson($accept, $payload)->status();
        }, function () use ($central, $invitation) {
            return $this->withSession($central)->postJson("/vexo-admin/companies/{$this->a->id}/accesses/{$invitation->id}/resend",
                ['expected_version' => $invitation->version])->status();
        });
        $record = $invitation->fresh();
        app(TenantConnection::class)->connect($this->a);
        $created = User::where('email', $email);
        if ($record->status === 'accepted') {
            $this->assertLessThan(400, $attempts[0]);
            $this->assertSame($created->sole()->id, $record->user_id);
        } else {
            $this->assertSame('pending', $record->status);
            $this->assertSame(422, $attempts[0]);
            $this->assertLessThan(400, $attempts[1]);
            $this->assertNotSame(hash('sha256', $token), $record->token_digest);
            $this->assertFalse($created->exists());
        }
        $this->postJson($accept, $payload)->assertUnprocessable();
    }

    public function test_installer_smtp_failure_keeps_the_account_inactive_and_revoked_tokens_cannot_activate_it(): void
    {
        config(['mail.default' => 'smtp']);
        $email = 'smtp-indisponivel@example.test';
        $admin = $this->licensingAdmin();
        $this->withSession(['platform_admin_id' => $admin->id, 'platform_auth_version' => 0]);
        $base = "/vexo-admin/companies/{$this->a->id}/accesses";
        $this->get($base)->assertOk()->assertDontSee('name="mfa_code"',false);
        $originalMailer = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->once()->andReturnUsing(function () {
            $this->assertSame('pending', MailAttempt::sole()->status);
            throw new \RuntimeException('SMTP-QA-ERRO-PRIVADO');
        });
        $response = $this->post($base, ['name' => 'Implantador QA', 'email' => $email]);
        $this->assertLessThan(500, $response->status());
        $invitation = InstallerInvitation::where('email', $email)->sole();
        $this->assertSame('failed', $invitation->delivery_status);
        $this->assertNull($invitation->user_id);
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse(User::where('email', $email)->exists());
        Mail::swap($originalMailer);
        Mail::fake();
        $this->post("$base/{$invitation->id}/resend", ['expected_version' => $invitation->version])->assertRedirect()->assertSessionHasNoErrors();
        $token = $this->sentInstallerToken($email);
        $this->post("$base/{$invitation->id}/revoke", ['expected_version' => $invitation->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->flushSession();
        $this->postJson('/app/a/convite-implantacao', ['token' => $token, 'email' => $email,
            'password' => 'Senha de QA após revogação', 'password_confirmation' => 'Senha de QA após revogação'])->assertUnprocessable();
        $this->assertSame('revoked', $invitation->fresh()->status);
        $attempts = MailAttempt::where('type', 'installer_invitation')->orderBy('id')->get();
        $this->assertSame(['failed', 'sent'], $attempts->pluck('status')->all());
        foreach ($attempts as $attempt) {
            $this->assertSame($email, $attempt->recipient);
            $this->assertSame($invitation->id, (int) $attempt->source_id);
            $this->assertSame($this->a->id, (int) $attempt->tenant_id);
            $raw = json_encode($attempt->getRawOriginal(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($token, $raw);
            $this->assertStringNotContainsString('#token=', $raw);
            $this->assertStringNotContainsString('SMTP-QA-ERRO-PRIVADO', $raw);
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse(User::where('email', $email)->exists());
    }

    public function test_legacy_installer_recovery_and_explicit_dba_grant_keep_password_and_scope_to_one_store(): void
    {
        $email = 'implantador-perfil@example.test';
        [$grant, $token, $central] = $this->inviteInstaller($email);
        $password = 'Senha escolhida da loja 123';
        $this->flushSession();
        $this->post('/app/a/convite-implantacao', ['email' => $email, 'token' => $token,
            'password' => $password, 'password_confirmation' => $password])
            ->assertRedirect('/app/a/setup')->assertSessionHasNoErrors();
        $this->post('/app/a/logout')->assertRedirect('/app/a/login');
        $connect = app(TenantConnection::class);
        $connect->connect($this->a);
        $user = User::where('email', $email)->sole();
        $originalHash = $user->password;
        $completed = now()->subDay()->startOfMinute();
        $setup = CompanyOperation::findOrFail(1);
        $setup->update(['completed_at' => $completed, 'configuration' => $setup->configuration +
            ['_onboarding_completed_at' => $completed->toIso8601String()]]);
        \App\Models\OperationRecord::create(['action' => 'completed', 'user_id' => $this->user->id,
            'version' => $setup->version, 'before' => [], 'after' => ['completed_at' => $completed->toIso8601String()],
            'created_at' => $completed]);
        User::whereKey($user->id)->update(['active' => false, 'auth_version' => $user->auth_version + 1,
            'updated_at' => $completed->copy()->addHour()]);
        $login = ['email' => strtoupper($email), 'password' => $password];
        $this->post('/app/a/login', $login)->assertSessionHasErrors('email')->assertSessionMissing('user_id');
        $connect->connect($this->a);
        $this->assertFalse($user->fresh()->active);
        User::whereKey($user->id)->update(['updated_at' => $completed]);
        $this->post('/app/a/login', array_replace($login, ['password' => 'senha-incorreta']))
            ->assertSessionHasErrors('email')->assertSessionMissing('user_id');
        $connect->connect($this->a);
        $this->assertFalse($user->fresh()->active);
        $this->post('/app/a/login', $login)->assertRedirect('/app/a/implantacao/situacao')->assertSessionHasNoErrors();
        $this->get('/app/a/users')->assertForbidden();
        $connect->connect($this->a);
        $this->assertTrue($user->fresh()->active);
        $this->assertSame($originalHash, $user->fresh()->password);
        $base = "/vexo-admin/companies/{$this->a->id}/accesses/{$grant->id}/update";
        $dba = ['enabled' => 1, 'access_profile' => 'tenant_dba', 'expected_version' => $grant->fresh()->version];
        $this->post($base, $dba)->assertRedirect('/vexo-admin/login');
        $this->withSession($central)->post("/vexo-admin/companies/{$this->b->id}/accesses/{$grant->id}/update", $dba)->assertNotFound();
        $this->post($base, $dba)->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/a/users')->assertRedirect('/app/a/login');
        $this->flushSession();
        $this->post('/app/a/login', $login)->assertRedirect('/app/a')->assertSessionHasNoErrors();
        $this->get('/app/a/users')->assertOk();
        $this->get('/app/a/desk')->assertOk();
        $this->get('/app/a/implantacao/situacao')->assertForbidden();
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        $this->get('/app/b/users')->assertRedirect('/app/b/login');
        $connect->connect($this->a);
        $this->assertSame(User::ROLE_INSTALLER, $user->fresh()->role);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->post('/app/a/login', $login)->assertRedirect('/app/a');
        $this->withSession($central)->post($base, ['enabled' => 0, 'access_profile' => 'tenant_dba',
            'expected_version' => $grant->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/a/users')->assertRedirect('/app/a/login');
        $this->flushSession();
        $this->post('/app/a/login', $login)->assertSessionHasErrors('email')->assertSessionMissing('user_id');
        $this->withSession($central)->post($base, ['enabled' => 1, 'access_profile' => 'installer',
            'expected_version' => $grant->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->flushSession();
        $this->post('/app/a/login', $login)->assertRedirect('/app/a/implantacao/situacao');
        $this->get('/app/a/users')->assertForbidden();
        $connect->connect($this->a);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertTrue($this->user->fresh()->active);
    }

    public function test_installer_can_return_to_read_only_status_after_setup_until_central_revocation(): void
    {
        $attributes = ['name' => 'Implantação QA', 'email' => 'implantacao@example.test',
            'role' => 'installer', 'password' => 'senha-escolhida-em-qa-123', 'must_change_password' => false];
        $owner = $this->user;
        $connect = app(TenantConnection::class);
        $connect->connect($this->b);
        $otherStoreInstaller = User::create($attributes);
        $connect->connect($this->a);
        $installer = User::create($attributes);
        $peer = User::create(array_replace($attributes, ['email' => 'colega@example.test']));
        $admin = $this->licensingAdmin();
        foreach ([[$this->b, $otherStoreInstaller], [$this->a, $installer], [$this->a, $peer]] as [$tenant, $user]) {
            InstallerInvitation::create(['tenant_id' => $tenant->id, 'user_id' => $user->id,
                'issued_by' => $admin->id, 'name' => $user->name, 'email' => $user->email,
                'status' => 'accepted', 'delivery_status' => 'sent', 'accepted_at' => now()]);
        }
        $this->post('/app/a/login', ['email' => $installer->email, 'password' => $attributes['password']])
            ->assertRedirect('/app/a/setup')->assertSessionHasNoErrors();
        $this->get('/app/b/setup')->assertRedirect('/app/b/login');
        $this->post('/app/a/login', ['email' => $installer->email, 'password' => $attributes['password']])
            ->assertRedirect('/app/a/setup');
        foreach (['desk', 'finance', 'users', 'business'] as $path) {
            $this->get('/app/a/'.$path)->assertForbidden();
        }
        $this->get('/vexo-admin')->assertRedirect('/vexo-admin/login');
        foreach (['/app/a/account/password', '/app/a/setup?step=1'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('/app/a/licenca', false)->assertDontSee('/vexo-admin', false);
        }
        $this->get('/app/a/setup?step=1')->assertOk()->assertSee('Buscar CNPJ');
        $this->post('/app/a/setup/company', ['legal_name' => 'Empresa de implantação QA',
            'document' => '07.396.600/0001-60', 'state' => 'MG', 'city' => 'Lavras', 'municipality_code' => '3138203'])
            ->assertRedirect('/app/a/setup?step=2')->assertSessionHasNoErrors();
        $connect->connect($this->a);
        $operation = CompanyOperation::findOrFail(1);
        $this->post('/app/a/setup', $this->operationPayload(['version' => $operation->version]))
            ->assertRedirect('/app/a/setup?step=4')->assertSessionHasNoErrors();
        $this->get('/app/a/setup?step=4')->assertOk()->assertDontSee('name="password"', false);
        $account = ['name' => 'Operador QA', 'email' => 'operador@example.test', 'role' => 'admin',
            'password' => 'senha-local-operador-123', 'password_confirmation' => 'senha-local-operador-123'];
        $this->post('/app/a/users', $account)->assertForbidden();
        $this->post('/app/a/setup/team', $account + ['active' => 1])->assertForbidden();
        $connect->connect($this->a);
        $this->assertSame(3, User::count());
        $this->assertSame(0, Seller::count());
        $this->post('/app/a/setup/team', ['name' => 'Responsável da loja', 'active' => 1])
            ->assertRedirect('/app/a/setup?step=5')->assertSessionHasNoErrors();
        $connect->connect($this->a);
        $this->post('/app/a/setup/complete', ['version' => $operation->fresh()->version, 'reviewed' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $status = '/app/a/implantacao/situacao';
        $this->get($status)->assertOk();
        $connect->connect($this->a);
        $this->assertNotNull($operation->fresh()->completed_at);
        $this->assertTrue($installer->fresh()->active);
        $this->assertTrue($peer->fresh()->active);
        $version = $operation->fresh()->version;
        $this->get('/app/a/desk')->assertForbidden();
        $this->post('/app/a/setup/company', ['legal_name' => 'Alteração após conclusão'])->assertForbidden();
        $connect->connect($this->a);
        $this->assertSame($version, $operation->fresh()->version);
        $this->post('/app/a/logout')->assertRedirect('/app/a/login');
        $this->post('/app/a/login', ['email' => $installer->email, 'password' => 'senha-incorreta'])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.'])->assertSessionMissing('user_id');
        $this->post('/app/a/login', ['email' => strtoupper($installer->email), 'password' => $attributes['password']])
            ->assertRedirect($status)->assertSessionHasNoErrors();
        $this->get($status)->assertOk();
        $grant = InstallerInvitation::where('tenant_id', $this->a->id)->where('user_id', $installer->id)->sole();
        $this->withSession(['platform_admin_id' => $admin->id, 'platform_auth_version' => $admin->auth_version,
            'platform_mfa_at' => time()])->post("/vexo-admin/companies/{$this->a->id}/accesses/{$grant->id}/revoke",
            ['expected_version' => $grant->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($status)->assertRedirect('/app/a/login');
        $this->flushSession();
        $this->post('/app/a/login', ['email' => $installer->email, 'password' => $attributes['password']])
            ->assertSessionHasErrors(['email' => 'E-mail ou senha inválidos.'])->assertSessionMissing('user_id');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $owner->id, 'auth_version' => 0])
            ->get('/app/a/desk')->assertOk();
        $connect->connect($this->b);
        $this->assertTrue($otherStoreInstaller->fresh()->active);
        $this->assertNull(CompanyOperation::find(1));
    }
}
