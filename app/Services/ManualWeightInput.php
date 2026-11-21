<?php

namespace App\Services;

use App\Contracts\WeightInput;

final class ManualWeightInput implements WeightInput
{
    public function quantity(string $measurement): string
    {
        return Decimal::input($measurement, 3, 'quantity', '0.001', '999999999.999');
    }
}
