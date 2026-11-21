<?php

namespace App\Fiscal\Nfse;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class Access
{
    public static function admin(Tenant $tenant, int $actor): void
    {
        abort_unless(app(\App\Services\TenantConnection::class)->matches($tenant), 403);
        \App\Services\InstallerAccess::authorizedActor($actor, 'fiscal.manage');
    }
}
