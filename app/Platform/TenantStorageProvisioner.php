<?php

namespace App\Platform;

use App\Models\Tenant;

/** Privileged CLI-only storage adapter. HTTP never binds or calls this interface. */
interface TenantStorageProvisioner
{
    /** Install only this new namespace, runtime account and initial company/admin records. */
    public function provisionNew(Tenant $tenant, array $adminAttributes, #[\SensitiveParameter] string $password): void;

    /** Remove only resources created for this new tenant; never truncate shared tables or other tenants. */
    public function rollbackNew(Tenant $tenant): void;
}
