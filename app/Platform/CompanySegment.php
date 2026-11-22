<?php

namespace App\Platform;

use App\Models\CompanyOperation;
use App\Services\OperationalModes;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\DB;

final class CompanySegment
{
    /** Project only the segment recorded by this company's wizard. */
    public function sync(int $tenantId, ?CompanyOperation $settings): void
    {
        abort_unless(app(TenantConnection::class)->matchesId($tenantId), 403);
        $segment = $settings?->configuration['segment'] ?? null;
        $version = $settings?->version ?? 0;

        DB::connection('central')->table('tenants')->where('id', $tenantId)
            ->where(fn ($query) => $query->whereNull('operation_segment_version')->orWhere('operation_segment_version', '<=', $version))
            ->update([
                'operation_segment' => is_string($segment) && isset(OperationalModes::SEGMENTS[$segment]) ? $segment : null,
                'operation_segment_version' => $version,
            ]);
    }
}
