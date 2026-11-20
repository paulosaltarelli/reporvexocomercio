<?php

namespace App\Platform;

/** RFC 6238, SHA-1, six digits, 30-second time step. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        $secret = '';
        for ($index = 0; $index < 32; $index++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }
        return $secret;
    }

    public static function code(string $secret, int $step): string
    {
        if (!preg_match('/^[A-Z2-7]{32}$/D', $secret) || $step < 0) {
            throw new \InvalidArgumentException('Parâmetros TOTP inválidos.');
        }
        $bits = '';
        foreach (str_split($secret) as $letter) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $letter)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            $key .= chr(bindec($byte));
        }
        $digest = hash_hmac('sha1', pack('J', $step), $key, true);
        $offset = ord($digest[19]) & 15;
        $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
        return sprintf('%06d', $number % 1000000);
    }

    public static function matchStep(string $secret, string $input, int $timestamp, ?int $previous): ?int
    {
        $current = intdiv($timestamp, 30);
        foreach ([$current, $current - 1, $current + 1] as $step) {
            if ($step >= 0 && $step > ($previous ?? -1) && hash_equals(self::code($secret, $step), $input)) {
                return $step;
            }
        }
        return null;
    }
}
