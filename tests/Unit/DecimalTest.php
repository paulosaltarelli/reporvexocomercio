<?php

namespace Tests\Unit;

use App\Services\Decimal;
use PHPUnit\Framework\TestCase;

class DecimalTest extends TestCase
{
    public function test_margin_is_on_final_sale_price(): void
    {
        $this->assertSame('100.00', Decimal::price('60', '40'));
        $this->assertSame('60.00', Decimal::price('60', '0'));
    }

    public function test_rounding_is_decimal_half_up(): void
    {
        $this->assertSame('1.01', Decimal::round('1.005'));
        $this->assertSame('-1.01', Decimal::round('-1.005'));
        $this->assertSame('12.3457', Decimal::round('12.34565', 4));
    }

    public function test_invalid_margin_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Decimal::price('60', '100');
    }
}
