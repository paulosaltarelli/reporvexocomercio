<?php

namespace App\Platform;

final class PlatformMfa
{
    /** The caller locks the administrator row within a central transaction. */
    public static function consume(PlatformAdmin $admin, string $code): bool
    {
        if (!$admin->mfa_secret) {
            return false;
        }
        if (preg_match('/^[0-9]{6}$/D', $code)) {
            $step = Totp::matchStep($admin->mfa_secret, $code, time(), $admin->mfa_last_step);
            if ($step === null) {
                return false;
            }
            $admin->mfa_last_step = $step;
        } else {
            $codes = $admin->mfa_recovery ?? [];
            $index = array_search(hash('sha256', strtolower(trim($code))), $codes, true);
            if ($index === false) {
                return false;
            }
            unset($codes[$index]);
            $admin->mfa_recovery = array_values($codes);
        }
        $admin->save();
        return true;
    }

    public static function recent(int $timestamp): bool
    {
        $now = time();
        return $timestamp > 0 && $timestamp <= $now && $timestamp >= $now - 300;
    }
}
