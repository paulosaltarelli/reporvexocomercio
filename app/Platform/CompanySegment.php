<?php

namespace App\Platform;

use App\Models\CompanyOperation;
use App\Services\CompanyOperations;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\DB;

final class CompanySegment
{
    /** Project the wizard's effective segment from the same revision of the store configuration. */
    public function sync(int $tenantId, ?CompanyOperation $settings): void
    {
        abort_unless(app(TenantConnection::class)->matchesId($tenantId), 403);
        $segment = $settings === null ? null : CompanyOperations::effectiveSegment($settings->configuration ?? []);
        $version = $settings?->version ?? 0;

        DB::connection('central')->table('tenants')->where('id', $tenantId)
            ->where(fn ($query) => $query->whereNull('operation_segment_version')->orWhere('operation_segment_version', '<=', $version))
            ->update([
                'operation_segment' => $segment,
                'operation_segment_version' => $version,
            ]);
    }
}
