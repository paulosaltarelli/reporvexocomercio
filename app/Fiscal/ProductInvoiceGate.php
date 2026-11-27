<?php

namespace App\Fiscal;

use App\Services\TenantConnection;

final class ProductInvoiceGate
{
    public static function globallyEnabled(): bool
    {
        return config('fiscal.product_invoices_enabled', false) === true;
    }

    /** Central IDs only. Reject the whole setting if any entry is malformed. */
    public static function pilotTenantIds(): array
    {
        $setting = config('fiscal.nfce_homologation_tenant_ids');
        if (! is_string($setting) || trim($setting) === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $setting) as $value) {
            $value = trim($value);
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1 || (string) $id !== $value) {
                return [];
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    }

    /** A null model denotes features available only with the full product invoice flag. */
    public static function enabled(?int $model = 65): bool
    {
        $tenantId = config('tenancy.resolved_tenant_id');
        if (! is_int($tenantId) || $tenantId <= 0 || ! in_array($model, [null, 65, 55], true)) {
            return false;
        }
        if (! self::globallyEnabled() && ($model !== 65 || ! in_array($tenantId, self::pilotTenantIds(), true))) {
            return false;
        }
        return app(TenantConnection::class)->matchesId($tenantId);
    }

    public static function assertEnabled(?int $model = 65): void
    {
        abort_unless(self::enabled($model), 404);
    }
}
