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
        abort_unless(User::whereKey($actor)->where('active', true)->where('role', 'admin')->exists(), 403);
    }
}
