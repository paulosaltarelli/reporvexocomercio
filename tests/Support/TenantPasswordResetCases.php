<?php

namespace Tests\Support;

use App\Mail\TenantPasswordResetMail;
use App\Models\User;
use App\Platform\InstallerInvitation;
use App\Platform\MailAttempt;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

trait TenantPasswordResetCases
{
    private function resetMailToken(string $slug, string $email): string
    {
        $message = Mail::sent(TenantPasswordResetMail::class)->last();
        $this->assertNotNull($message);
        $this->assertTrue($message->hasTo($email));
        $this->assertCount(1, $message->to);
        $this->assertEmpty($message->cc);
        $this->assertEmpty($message->bcc);
        $body = html_entity_decode($message->render());
        $this->assertMatchesRegularExpression("~https?://[^\s\"<>]*/app/$slug/redefinir-senha#token=([a-f0-9]{64})~", $body);
        preg_match('/#token=([a-f0-9]{64})/', $body, $match);
        return $match[1];
    }

    public function test_password_reset_is_tenant_scoped_single_use_and_invalidates_old_sessions_without_authenticating(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $email = $this->user->email;
        $connect = app(TenantConnection::class);
        $connect->connect($this->b);
        $other = User::create(['name' => 'Outra loja', 'email' => $email, 'role' => 'admin', 'password' => 'senha-anterior-loja-b']);
        $oldHash = $other->fresh()->password;
        $this->get('/app/a/login')->assertOk()->assertSee('/app/a/recuperar-senha', false);
        $request = '/app/a/recuperar-senha';
        $reset = '/app/a/redefinir-senha';
        $this->get($request)->assertOk();
        $this->get($reset)->assertOk();
        $unknown = $this->post($request, ['email' => 'ausente@example.test'])->assertRedirect($request)->assertSessionHasNoErrors();
        $notice = session('success');
        $this->assertNotEmpty($notice);
        Mail::assertNothingSent();
        $this->post($request, ['email' => strtoupper($email)])->assertRedirect($request)->assertSessionHas('success', $notice);
        $token = $this->resetMailToken('a', $email);
        $unknown->assertDontSee($token);
        $this->assertStringNotContainsString($token, serialize(session()->all()));
        $this->post($request, ['email' => $email])->assertRedirect($request)->assertSessionHas('success', $notice);
        Mail::assertSent(TenantPasswordResetMail::class, 1);
        $rows = DB::connection('central')->table('platform_password_resets');
        $record = $rows->where('tenant_id', $this->a->id)->where('user_id', $this->user->id)->first();
        $this->assertSame(hash('sha256', $token), $record->token_digest);
        $this->assertStringNotContainsString($token, json_encode($record, JSON_THROW_ON_ERROR));
        $this->post('/app/b/recuperar-senha', ['email' => $email])->assertSessionHas('success', $notice);
        $otherToken = $this->resetMailToken('b', $email);
        $this->assertNotSame($token, $otherToken);
        $newPassword = 'Senha redefinida da loja A 123';
        $payload = ['email' => $email, 'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword];
        $this->postJson('/app/b/redefinir-senha', $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson($reset, array_replace($payload, ['email' => 'nao-e-o-titular@example.test']))->assertUnprocessable();
        $connect->connect($this->a);
        $previous = $this->user->fresh();
        $this->assertTrue(Hash::check('test-password-123', $previous->password));
        $this->post($reset, $payload)->assertRedirect('/app/a/login')->assertSessionHasNoErrors()->assertSessionMissing('user_id');
        $this->postJson($reset, $payload)->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $previous->id, 'auth_version' => $previous->auth_version])
            ->get('/app/a/desk')->assertRedirect('/app/a/login');
        $this->flushSession();
        $this->post('/app/a/login', ['email' => $email, 'password' => 'test-password-123'])->assertSessionHasErrors('email');
        $this->post('/app/a/login', ['email' => $email, 'password' => $newPassword])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/a/desk')->assertOk();
        $connect->connect($this->a);
        $this->assertSame($previous->auth_version + 1, $previous->fresh()->auth_version);
        $connect->connect($this->b);
        $this->assertSame($oldHash, $other->fresh()->password);
        $attempts = MailAttempt::where('type', 'password_reset')->orderBy('id')->get();
        $this->assertSame(['sent','sent'], $attempts->pluck('status')->all());
        $this->assertSame([$this->a->id, $this->b->id], $attempts->pluck('tenant_id')->map(fn ($id) => (int) $id)->all());
        $this->flushSession();
        $this->get('/vexo-admin/settings/emails/'.$attempts->first()->id)->assertRedirect('/vexo-admin/login');
        $admin = $this->licensingAdmin();
        $this->withSession(['platform_admin_id' => $admin->id, 'platform_auth_version' => $admin->auth_version, 'platform_mfa_at' => time()])
            ->get('/vexo-admin/settings/emails/'.$attempts->first()->id)->assertOk()->assertSee($email)
            ->assertSee('srcdoc=', false)->assertDontSee($token)->assertDontSee($otherToken)->assertDontSee('#token=');
        foreach ($attempts as $attempt) {
            $raw = json_encode($attempt->getRawOriginal(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($token, $raw);
            $this->assertStringNotContainsString($otherToken, $raw);
            $this->assertStringNotContainsString($newPassword, $raw);
        }
    }

    public function test_password_reset_expiry_rotation_and_competing_submissions_allow_one_password_change(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $email = $this->user->email;
        $request = '/app/a/recuperar-senha';
        $reset = '/app/a/redefinir-senha';
        $this->post($request, ['email' => $email])->assertRedirect($request);
        $token = $this->resetMailToken('a', $email);
        $newPassword = 'Senha recuperada apenas uma vez';
        $payload = ['email' => $email, 'token' => $token, 'password' => $newPassword, 'password_confirmation' => $newPassword];
        $this->travel(31)->minutes();
        $this->postJson($reset, $payload)->assertUnprocessable();
        $this->post($request, ['email' => $email])->assertRedirect($request);
        $payload['token'] = $this->resetMailToken('a', $email);
        $this->travel(61)->seconds();
        $this->post($request, ['email' => $email])->assertRedirect($request);
        $this->postJson($reset, $payload)->assertUnprocessable();
        $payload['token'] = $this->resetMailToken('a', $email);
        $results = $this->race(null, fn () => $this->postJson($reset, $payload)->status());
        sort($results);
        $this->assertSame([302, 422], $results);
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, $this->user->fresh()->auth_version);
        $this->assertTrue(Hash::check($newPassword, $this->user->fresh()->password));
    }

    public function test_password_reset_cannot_reactivate_an_installer_when_the_central_grant_is_revoked(): void
    {
        $email = 'implantador-reset@example.test';
        [$grant, $inviteToken, $central] = $this->inviteInstaller($email);
        $original = 'Senha de implantação QA 123';
        $this->flushSession();
        $this->post('/app/a/convite-implantacao', ['email' => $email, 'token' => $inviteToken, 'password' => $original,
            'password_confirmation' => $original])->assertRedirect('/app/a/setup');
        $this->post('/app/a/logout');
        $this->post('/app/a/recuperar-senha', ['email' => $email])->assertRedirect();
        $notice = session('success');
        $token = $this->resetMailToken('a', $email);
        $url = "/vexo-admin/companies/{$this->a->id}/accesses/{$grant->id}/revoke";
        $this->withSession($central)->post($url, ['expected_version' => $grant->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->flushSession();
        $this->postJson('/app/a/redefinir-senha', ['email' => $email, 'token' => $token, 'password' => 'Senha nova incorreta QA',
            'password_confirmation' => 'Senha nova incorreta QA'])->assertUnprocessable();
        $this->travel(61)->seconds();
        $this->post('/app/a/recuperar-senha', ['email' => $email])->assertRedirect()->assertSessionHas('success', $notice);
        Mail::assertSent(TenantPasswordResetMail::class, 1);
        app(TenantConnection::class)->connect($this->a);
        $installer = User::where('email', $email)->sole();
        $this->assertFalse($installer->active);
        $this->assertTrue(Hash::check($original, $installer->password));
        $this->assertSame('revoked', $grant->fresh()->status);
    }

    public function test_password_reset_request_rate_limit_applies_to_unregistered_addresses_too(): void
    {
        Mail::fake();
        $uri = '/app/a/recuperar-senha';
        for ($i = 0; $i < 10; ++$i) {
            $this->post($uri, ['email' => "ausente-{$i}@example.test"])->assertRedirect($uri);
        }
        $this->post($uri, ['email' => 'mais-uma@example.test'])->assertTooManyRequests();
        Mail::assertNothingSent();
    }
}
