<?php

namespace App\Fiscal;

use App\Models\FiscalIssuer;
use App\Models\Tenant;
use Illuminate\Validation\ValidationException;

final class QueueVoid
{
    public function enqueue(Tenant $tenant, FiscalIssuer $issuer, int $userId, int $last, string $reason, string $key): int
    {
        throw ValidationException::withMessages(['fiscal' => 'Inutilização ainda não habilitada na integração direta. Nenhuma numeração foi consumida.']);
    }
}
