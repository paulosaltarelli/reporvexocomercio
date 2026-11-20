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
        $tenantId = (int) config('tenancy.resolved_tenant_id');

        return $user->role === User::ROLE_INSTALLER && $user->active && app(TenantConnection::class)->matchesId($tenantId)
            && InstallerInvitation::where('tenant_id', $tenantId)->where('user_id', $user->id)
                ->where('email', $user->email)->where('status', 'accepted')->exists();
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
        return $request->routeIs('setup', 'setup.company.save', 'setup.save', 'setup.fiscal.continue', 'setup.team.save',
            'setup.complete', 'business.logo', 'business.cnpj', 'account.password', 'account.password.save', 'logout');
    }

    /** Call after locking company_operations: recheck role, revocation and completion inside the mutation. */
    public static function wizardActor(int $actor, CompanyOperation $setup): User
    {
        $user = User::whereKey($actor)->where('active', true)->lockForUpdate()->first();
        abort_unless($user && ($user->role === 'admin' || (self::open($setup) && self::granted($user))), 403);

        return $user;
    }
}
