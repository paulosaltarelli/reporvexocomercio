<?php

namespace Tests\Unit;

use App\Fiscal\TaxProfile;
use Illuminate\Validation\ValidationException;
use Tests\Support\FiscalFixtures;
use Tests\TestCase;

class TaxProfileDirectScopeTest extends TestCase
{
    public function test_unimplemented_cfop_is_rejected_even_when_its_shape_is_valid(): void
    {
        $this->expectException(ValidationException::class);
        (new TaxProfile)->validate(array_replace(FiscalFixtures::rule(), ['cfop' => '5405']));
    }

    public function test_unimplemented_rtc_class_is_rejected_instead_of_accepting_any_six_digits(): void
    {
        $this->expectException(ValidationException::class);
        (new TaxProfile)->validate(array_replace(FiscalFixtures::rule(), ['rtc_mode' => 'standard', 'rtc_cst' => '000', 'rtc_class' => '999999', 'ibs_uf_rate' => '0.1', 'ibs_municipal_rate' => '0', 'cbs_rate' => '0.9']));
    }

    public function test_free_text_non_applicability_does_not_enable_direct_calculation(): void
    {
        $this->expectException(ValidationException::class);
        (new TaxProfile)->fields(array_replace(FiscalFixtures::rule(), ['rtc_mode' => 'not_applicable', 'rtc_reason' => 'Texto não substitui um tratamento implementado.']), '100.00', '1');
    }
}
