<?php

namespace App\Platform;

use App\Models\Tenant;

/** Infrastructure implementation must be explicitly bound after isolation is proven. */
interface CompanyProvisioner
{
    public function available(): bool;
    public function provision(array $data, PlatformAdmin $actor): Tenant;
}
