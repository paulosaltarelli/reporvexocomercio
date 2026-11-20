<?php

namespace App\Fiscal;

final class ProductInvoiceGate
{
    public static function enabled(): bool
    {
        return config('fiscal.product_invoices_enabled', false) === true;
    }

    public static function assertEnabled(): void
    {
        abort_unless(self::enabled(), 404);
    }
}
