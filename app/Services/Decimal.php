<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Decimal
{
    /** Validate before BCMath: never silently truncate extra precision or accept exponents. */
    public static function input(string $value, int $scale, string $field, string $min = '0', string $max = '999999999999.99'): string
    {
        $pattern = $scale === 0 ? '/^\\d+$/D' : '/^\\d+(?:\\.\\d{1,'.$scale.'})?$/D';
        if (! preg_match($pattern, $value) || bccomp($value, $min, $scale) < 0 || bccomp($value, $max, $scale) > 0) {
            throw ValidationException::withMessages([$field => 'Valor inválido, fora do limite ou com casas decimais em excesso.']);
        }

        return bcadd($value, '0', $scale);
    }

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
