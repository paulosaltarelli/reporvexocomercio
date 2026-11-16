<?php

namespace App\Platform;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CompanyAdministration
{
    public function status(int $companyId, array $input, int $actorId, int $authVersion): Tenant
    {
        if (isset($input['reason']) && is_string($input['reason'])) $input['reason'] = trim($input['reason']);
        $data = Validator::make($input, ['active' => 'required|boolean', 'expected_version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:1000'])->validate();
        return DB::connection('central')->transaction(function () use ($companyId, $data, $actorId, $authVersion) {
            $actor = PlatformAdmin::whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor && $actor->active && (int) $actor->auth_version === $authVersion, 403);
            $tenant = Tenant::whereKey($companyId)->lockForUpdate()->firstOrFail();
            if ((int) $tenant->platform_version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'A empresa foi alterada. Atualize a página antes de confirmar.']);
            }
            $next = (bool) $data['active'];
            if ($next && $tenant->storage_mode==='shared' && !$tenant->provisioned_at) {
                throw ValidationException::withMessages(['active'=>'A implantação precisa ser concluída antes de ativar a empresa.']);
            }
            if ($tenant->active === $next) {
                return $tenant;
            }
            $before = $tenant->active;
            $tenant->active = $next;
            $tenant->platform_version = (int) $tenant->platform_version + 1;
            $tenant->save();
            PlatformAudit::create(['platform_admin_id' => $actor->id, 'tenant_id' => $tenant->id, 'actor_name' => $actor->name, 'action' => $next ? 'company.activated' : 'company.deactivated', 'reason' => $data['reason'], 'changes' => ['active' => ['before' => $before, 'after' => $next]]]);
            return $tenant;
        }, 3);
    }
}
