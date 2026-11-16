<?php

namespace App\Fiscal\Nfse;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class Access
{
    public static function admin(Tenant $tenant, int $actor): void
    {
        abort_unless(app(\App\Services\TenantConnection::class)->matches($tenant), 403);
        abort_unless((bool) User::whereKey($actor)->where('active', true)->first()?->allows('fiscal.manage'), 403);
    }
}
