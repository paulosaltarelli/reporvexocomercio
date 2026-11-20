<?php

namespace App\Services;

use App\Mail\TenantPasswordResetMail;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\MailAttempt;
use App\Platform\SmtpSettings;
use App\Platform\SupportAccess;
use App\Tenancy\CompanyHosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

final class TenantPasswordReset
{
    private const TABLE = 'platform_password_resets';
    private const USER_COLUMNS = ['id', 'email', 'role', 'active', 'auth_version'];

    public function __construct(private TenantConnection $connection) {}

    private function eligible(?User $user, string $email): bool
    {
        if (!$user || !$user->active || mb_strtolower($user->email) !== $email || SupportAccess::isManaged($user)) {
            return false;
        }
        return match ($user->role) {
            'admin', 'operator' => true,
            User::ROLE_INSTALLER => InstallerAccess::applyGrant($user),
            default => false,
        };
    }

    /** Runs after the generic HTTP response, outside the request's tenant connection. */
    public function request(int $tenantId, string $email): void
    {
        $tenant = Tenant::whereKey($tenantId)->where('active', true)->first();
        if (!$tenant) return;
        $central = DB::connection('central');
        $token = bin2hex(random_bytes(32));
        $digest = hash('sha256', $token);
        try {
            $this->connection->connect($tenant);
            $delivery = $central->transaction(function () use ($central, $tenant, $email, $digest) {
                // Serialize issuance with another request for this account and central grant changes.
                if (!Tenant::whereKey($tenant->id)->where('active', true)->lockForUpdate()->first(['id'])) return null;
                $user = User::where('email', $email)->first(self::USER_COLUMNS);
                if (!$this->eligible($user, $email)) return null;
                $record = $central->table(self::TABLE)->where(['tenant_id' => $tenant->id, 'user_id' => $user->id]);
                if ((clone $record)->where('requested_at', '>', now()->subMinute())->exists()) return null;
                $data = ['email' => $email, 'auth_version' => $user->auth_version, 'token_digest' => $digest,
                    'requested_at' => now(), 'expires_at' => now()->addMinutes(30), 'sent_at' => null];
                $central->table(self::TABLE)->updateOrInsert(['tenant_id' => $tenant->id, 'user_id' => $user->id], $data);
                $id = (int)$record->value('id');
                $attempt = MailAttempt::begin($tenant->id, $tenant->name, $user->email,
                    TenantPasswordResetMail::SUBJECT, 'password_reset', $id);
                return [$id, $user->email, $attempt];
            });
        } finally {
            $this->connection->disconnect();
        }
        if (!$delivery) return;
        [$id, $recipient, $attempt] = $delivery;
        $sent = false;
        try {
            $url = CompanyHosts::url($tenant->slug, 'password.reset').'#token='.$token;
            app(SmtpSettings::class)->send(fn () => Mail::to($recipient)->send(new TenantPasswordResetMail($tenant->name, $url)));
            $sent = true;
        } catch (\Throwable) {
            // Raw transport exceptions can contain the message and the one-time link.
        }
        $central->transaction(function () use ($central, $id, $digest, $sent, $attempt) {
            $central->table(self::TABLE)->where('id', $id)->where('token_digest', $digest)
                ->update($sent ? ['sent_at' => now()] : ['token_digest' => null]);
            $attempt->finish($sent);
        });
    }

    public function reset(Tenant $tenant, string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): void
    {
        $this->connection->assertTenant($tenant);
        if (strlen($password) > 72) {
            throw ValidationException::withMessages(['password' => 'Use uma senha com no máximo 72 bytes.']);
        }
        $central = DB::connection('central');
        $central->transaction(function () use ($central, $tenant, $email, $token, $password) {
            if (!Tenant::whereKey($tenant->id)->where('active', true)->lockForUpdate()->first(['id'])) $this->invalid();
            $digest = hash('sha256', $token);
            $record = $central->table(self::TABLE)->where('tenant_id', $tenant->id)->where('email', $email)
                ->where('token_digest', $digest)->whereNotNull('sent_at')->where('expires_at', '>', now())->lockForUpdate()->first();
            if (!$record || !hash_equals($record->token_digest, $digest)) $this->invalid();

            DB::connection('tenant')->transaction(function () use ($record, $email, $password) {
                $user = User::whereKey($record->user_id)->lockForUpdate()->first(self::USER_COLUMNS);
                if (!$this->eligible($user, $email) || $user->auth_version !== (int)$record->auth_version) $this->invalid();
                $user->update(['password' => Hash::make($password), 'password_changed_at' => now(), 'must_change_password' => false,
                    'auth_version' => $user->auth_version + 1]);
            });
            // The tenant version also prevents replay if the final central write fails after the tenant commit.
            $central->table(self::TABLE)->where('id', $record->id)->update(['token_digest' => null]);
        });
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['token' => 'O link de recuperação é inválido ou expirou. Solicite outro link.']);
    }
}
