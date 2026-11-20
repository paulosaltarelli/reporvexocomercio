<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\InstallerInvitation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AcceptInstallerInvitation
{
    public function accept(Tenant $tenant, string $email, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): User
    {
        app(TenantConnection::class)->assertTenant($tenant);
        if (strlen($password) > 72 || mb_strlen($password) < 12) {
            throw ValidationException::withMessages(['password' => 'Use pelo menos 12 caracteres e no máximo 72 bytes.']);
        }
        $email = mb_strtolower(trim($email));
        if (! preg_match('/^[a-f0-9]{64}$/D', $token) || str_ends_with($email, '@support.vexo.invalid')) {
            $this->invalid();
        }
        $digest = hash('sha256', $token);
        try {
            return DB::connection('central')->transaction(function () use ($tenant, $email, $digest, $password) {
                $invite = InstallerInvitation::where('tenant_id', $tenant->id)->where('email', $email)
                    ->where('token_digest', $digest)->lockForUpdate()->first();
                if (! $invite || ! hash_equals(mb_strtolower($invite->email), $email) || $invite->status !== 'pending' || $invite->delivery_status !== 'sent'
                    || ! $invite->expires_at || $invite->expires_at <= now() || ! hash_equals((string) $invite->token_digest, $digest)) {
                    $this->invalid();
                }

                // A tenant commit can precede a central connection failure. The audit association
                // lets the same invitation recover that account without granting access until accepted centrally.
                $user = DB::connection('tenant')->transaction(function () use ($tenant, $invite, $password) {
                    $setup = InstallerAccess::lockSetup();
                    $account = User::where('email', $invite->email)->lockForUpdate()->first();
                    if ($account) {
                        if ($account->role !== User::ROLE_INSTALLER || ! hash_equals(mb_strtolower($account->email), mb_strtolower($invite->email))) {
                            $this->invalid();
                        }
                        $recovering = InstallerAccess::boundToInvite($account, (int) $invite->id);
                        $previouslyRevoked = InstallerAccess::boundToRevokedInvite($account, (int) $tenant->id);
                        if (! $previouslyRevoked && ! $recovering) {
                            $this->invalid();
                        }
                    }
                    $account ??= new User;
                    $account->fill(['name' => $invite->name, 'email' => $invite->email, 'role' => User::ROLE_INSTALLER, 'active' => true,
                        'password' => $password, 'must_change_password' => false, 'auth_version' => (int) $account->auth_version + 1, 'password_changed_at' => now()]);
                    $account->save();
                    if (empty($recovering)) {
                        OperationRecord::create(['action' => 'installer_invite_bound', 'version' => $setup->version, 'user_id' => $account->id,
                            'before' => [], 'after' => ['invitation_id' => (int) $invite->id], 'created_at' => now()]);
                    }

                    return $account;
                }, 3);

                $invite->update(['user_id' => $user->id, 'status' => 'accepted', 'accepted_at' => now(),
                    'token_digest' => null, 'version' => $invite->version + 1]);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            $this->invalid();
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['token' => 'O convite não está disponível para este e-mail e esta empresa.']);
    }
}
