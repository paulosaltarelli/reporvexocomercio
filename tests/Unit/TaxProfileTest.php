<?php

namespace Tests\Unit;

use App\Fiscal\TaxProfile;
use Illuminate\Validation\ValidationException;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

class TaxProfileTest extends TestCase
{
    public function test_decimal_tax_calculation_and_rtc_base_exclude_existing_tax_components(): void
    {
        $rule = array_replace(FiscalFixtures::rule(), ['icms_code' => '00', 'icms_rate' => '18', 'pis_code' => '01', 'pis_rate' => '1.65', 'cofins_code' => '01', 'cofins_rate' => '7.60', 'pis_cofins_base_mode' => 'exclude_icms', 'rtc_mode' => 'standard', 'rtc_cst' => '000', 'rtc_class' => '000001', 'ibs_uf_rate' => '0.1', 'ibs_municipal_rate' => '0', 'cbs_rate' => '0.9']);
        $fields = app(TaxProfile::class)->fields($rule, '100.01', '3');
        $this->assertSame('18.00', $fields['icms_valor']);
        $this->assertSame('1.35', $fields['pis_valor']);
        $this->assertSame('6.23', $fields['cofins_valor']);
        $this->assertSame('74.43', $fields['ibs_cbs_base_calculo']);
        $this->assertSame('0.07', $fields['ibs_uf_valor']);
        $this->assertSame('0.67', $fields['cbs_valor']);
    }

    public function test_no_tax_rate_is_invented_when_required(): void
    {
        $rule = array_replace(FiscalFixtures::rule(), ['icms_code' => '00']);
        $this->expectException(ValidationException::class);
        app(TaxProfile::class)->fields($rule, '100.00', '3');
    }

    public function test_regime_mismatch_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(TaxProfile::class)->fields(FiscalFixtures::rule(), '100.00', '3');
    }

    public function test_unimplemented_substitution_and_rtc_exceptions_are_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(TaxProfile::class)->fields(array_replace(FiscalFixtures::rule(), ['icms_code' => '500']), '100.00', '1');
    }
}
