<?php

namespace App\Platform;

final class Access
{
    public static function admin(int $id, ?int $version = null): PlatformAdmin
    {
        $admin = PlatformAdmin::where('active', true)->find($id);
        abort_unless($admin && ($version === null || (int) $admin->auth_version === $version), 403);
        return $admin;
    }
}
