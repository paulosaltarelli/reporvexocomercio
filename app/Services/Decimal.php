<?php

namespace App\Services;

class Decimal
{
    public static function round(string $value, int $scale = 2): string
    {
        $adjust = '0.'.str_repeat('0', $scale).'5';

        return bcadd($value, bccomp($value, '0', 10) < 0 ? '-'.$adjust : $adjust, $scale);
    }

    public static function price(string $cost, string $margin): string
    {
        if (bccomp($margin, '0', 2) < 0 || bccomp($margin, '100', 2) >= 0) {
            throw new \InvalidArgumentException('Margem deve estar entre 0 e 99,99%.');
        }

        return self::round(bcdiv($cost, bcsub('1', bcdiv($margin, '100', 8), 8), 8));
    }
}
