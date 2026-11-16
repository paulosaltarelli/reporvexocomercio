<?php

namespace App\Platform;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

final class UnavailableCompanyProvisioner implements CompanyProvisioner
{
    public function available(): bool
    {
        return false;
    }

    public function provision(array $data, PlatformAdmin $actor): Tenant
    {
        throw ValidationException::withMessages(['company' => 'Provisionamento indisponível: estratégia de isolamento ainda não validada. Nenhuma empresa foi criada.']);
    }
}
