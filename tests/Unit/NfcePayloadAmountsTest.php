<?php

namespace Tests\Unit;

use App\Fiscal\NfcePayload;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NfcePayloadAmountsTest extends TestCase
{
    public function test_implicit_and_explicit_discount_keep_one_coherent_fiscal_gross(): void
    {
        $line = (new NfcePayload)->fiscalAmounts('1.000', '90.00', '100.00', '14.50', '85.50');
        $this->assertSame('90.00', $line['commercial_unit_price']);
        $this->assertSame('100.0000000000', $line['unit_price']);
        $this->assertSame($line['gross'], bcadd(bcmul($line['quantity'], $line['unit_price'], 14), '0', 2));
        $this->assertSame('85.50', bcsub($line['gross'], $line['discount'], 2));
    }

    public function test_fractional_quantity_uses_supported_unit_precision_without_changing_cents(): void
    {
        $line = (new NfcePayload)->fiscalAmounts('0.003', '3000.00', '10.01', '1.01', '9.00');
        $this->assertSame('3336.6666666667', $line['unit_price']);
        $this->assertSame('10.01', $line['gross']);
        $this->assertSame('9.00', $line['net']);
    }

    public function test_inconsistent_frozen_gross_discount_and_net_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        (new NfcePayload)->fiscalAmounts('1.000', '90.00', '100.00', '14.50', '85.49');
    }

    public function test_unrepresentable_fiscal_unit_is_blocked_instead_of_changing_history(): void
    {
        $this->expectException(ValidationException::class);
        (new NfcePayload)->fiscalAmounts('0.001', '999999999999.99', '999999999999.99', '0.00', '999999999999.99');
    }
}
