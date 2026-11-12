<?php

namespace App\Fiscal;

/** Local format/DV validation only; does not assert registration or accreditation. */
final class TaxpayerDocument
{
    public static function normalize(string $value): string
    {
        return str_replace(['.', '/', '-', ' '], '', strtoupper(trim($value)));
    }

    public static function cnpj(string $value): bool
    {
        $value = self::normalize($value);
        if (! preg_match('/^[A-Z0-9]{12}[0-9]{2}$/D', $value) || preg_match('/^(.)\1{13}$/D', $value)) {
            return false;
        }
        $base = substr($value, 0, 12);
        foreach ([[5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2], [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]] as $weights) {
            $sum = 0;
            foreach ($weights as $i => $weight) {
                $sum += (ord($base[$i]) - 48) * $weight;
            }
            $remainder = $sum % 11;
            $base .= (string) ($remainder < 2 ? 0 : 11 - $remainder);
        }

        return hash_equals($value, $base);
    }

    public static function cpf(string $value): bool
    {
        $value = self::normalize($value);
        if (! preg_match('/^[0-9]{11}$/D', $value) || preg_match('/^(.)\1{10}$/D', $value)) {
            return false;
        }
        $base = substr($value, 0, 9);
        for ($length = 9; $length <= 10; $length++) {
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int) $base[$i] * ($length + 1 - $i);
            }
            $remainder = $sum % 11;
            $base .= (string) ($remainder < 2 ? 0 : 11 - $remainder);
        }

        return hash_equals($value, $base);
    }
}
