<?php

namespace App\Services;

use App\Models\CompanyOperation;
use App\Models\OperationRecord;
use App\Models\User;
use App\Platform\InstallerInvitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class InstallerAccess
{
    public static function open(?CompanyOperation $setup = null): bool
    {
        $setup ??= CompanyOperation::find(1);

        return ! $setup?->completed_at && empty($setup?->configuration['_onboarding_completed_at']);
    }

    public static function lockSetup(): CompanyOperation
    {
        if (! DB::connection('tenant')->transactionLevel()) {
            throw new \LogicException('A implantação exige transação da empresa.');
        }
        CompanyOperation::firstOrCreate(['id' => 1], ['configuration' => CompanyOperations::DEFAULTS]);

        return CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
    }

    public static function granted(User $user): bool
    {
        return $user->active && self::grantFor($user) !== null;
    }

    private static function grantFor(User $user): ?InstallerInvitation
    {
        $tenantId = (int) config('tenancy.resolved_tenant_id');
        if ($user->getRawOriginal('role') !== User::ROLE_INSTALLER || ! app(TenantConnection::class)->matchesId($tenantId)) {
            return null;
        }

        return InstallerInvitation::where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->where('email', $user->email)->where('status', 'accepted')
            ->first(['id', 'tenant_id', 'user_id', 'email', 'status', 'access_profile']);
    }

    public static function applyGrant(User $user): bool
    {
        $grant = $user->active ? self::grantFor($user) : null;
        if (!$grant) return false;
        if ($grant->access_profile === 'tenant_dba') $user->applyTenantDba();

        return in_array($grant->access_profile, ['installer', 'tenant_dba'], true);
    }

    public static function adminActor(int $actor): bool
    {
        $user = User::whereKey($actor)->where('active', true)->first(['id', 'role', 'active', 'email']);
        if (!$user) return false;
        return $user->role === 'admin' || (self::applyGrant($user) && $user->role === 'admin');
    }

    public static function disabledByCompletion(User $user, InstallerInvitation $invitation): bool
    {
        return !$user->active && $user->role === User::ROLE_INSTALLER
            && $invitation->status === 'accepted' && (int)$invitation->user_id === (int)$user->id
            && mb_strtolower($invitation->email) === mb_strtolower($user->email)
            && app(TenantConnection::class)->matchesId((int)$invitation->tenant_id)
            && !self::open() && self::boundToInvite($user, (int)$invitation->id)
            && OperationRecord::where('action', 'completed')->where('created_at', $user->updated_at)->exists();
    }

    /** Restore only accounts disabled by the previous completion routine. Called after password verification. */
    public static function restoreAfterCompletion(User $user): void
    {
        $tenantId = (int) config('tenancy.resolved_tenant_id');
        if (!app(TenantConnection::class)->matchesId($tenantId)) return;

        DB::connection('central')->transaction(function () use ($tenantId, $user) {
            // Use the same central-before-tenant locking order as revocation.
            $grant = InstallerInvitation::where('tenant_id', $tenantId)->where('user_id', $user->id)
                ->where('email', $user->email)->where('status', 'accepted')->lockForUpdate()
                ->first(['id', 'tenant_id', 'user_id', 'email', 'status']);
            if (!$grant) return;
            DB::connection('tenant')->transaction(function () use ($grant, $user) {
                $locked = User::whereKey($user->id)->lockForUpdate()
                    ->first(['id', 'email', 'role', 'active', 'auth_version', 'updated_at']);
                if (!$locked || $locked->auth_version !== $user->auth_version || !self::disabledByCompletion($locked, $grant)) return;

                $locked->update(['active' => true, 'auth_version' => $locked->auth_version + 1]);
                OperationRecord::create(['action' => 'installer_restored', 'user_id' => $user->id,
                    'version' => (int)CompanyOperation::whereKey(1)->value('version'), 'created_at' => now(),
                    'before' => ['active' => false], 'after' => ['active' => true, 'invitation_id' => (int)$grant->id]]);
                $user->active = true;
                $user->auth_version = $locked->auth_version;
            });
        });
    }

    public static function boundToInvite(User $user, int $invitationId): bool
    {
        return OperationRecord::where('action', 'installer_invite_bound')->where('user_id', $user->id)
            ->where('after->invitation_id', $invitationId)->exists();
    }

    public static function boundToRevokedInvite(User $user, int $tenantId): bool
    {
        if ($user->role !== User::ROLE_INSTALLER || ! app(TenantConnection::class)->matchesId($tenantId)) {
            return false;
        }
        $invites = InstallerInvitation::where('tenant_id', $tenantId)->where('email', $user->email);
        if ((clone $invites)->where('status', 'accepted')->exists()) {
            return false;
        }
        $history = OperationRecord::where('action', 'installer_invite_bound')->where('user_id', $user->id)
            ->get(['after'])->map(fn ($audit) => (int) $audit->after['invitation_id']);

        return $invites->where('status', 'revoked')->where(function ($query) use ($user, $history) {
            $query->where('user_id', $user->id)->orWhereIn('id', $history);
        })->exists();
    }

    public static function permits(Request $request): bool
    {
        if ($request->routeIs('installer.home', 'account.password', 'account.password.save', 'logout', 'business.logo')) return true;
        return $request->routeIs('setup', 'setup.company.save', 'setup.save', 'setup.fiscal.continue', 'setup.team.save',
            'setup.complete', 'business.cnpj') && self::open();
    }

    /** Call after locking company_operations: recheck role, revocation and completion inside the mutation. */
    public static function wizardActor(int $actor, CompanyOperation $setup): User
    {
        $user = User::whereKey($actor)->where('active', true)->lockForUpdate()->first();
        if ($user?->role === User::ROLE_INSTALLER) abort_unless(self::applyGrant($user), 403);
        abort_unless($user && ($user->role === 'admin' || ($user->role === User::ROLE_INSTALLER && self::open($setup))), 403);

        return $user;
    }
}
