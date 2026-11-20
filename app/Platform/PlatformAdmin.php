<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

class PlatformAdmin extends Model
{
    protected $connection = 'central';
    protected $guarded = ['id'];
    protected $hidden = ['password', 'mfa_secret', 'mfa_recovery', 'mfa_last_step'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'auth_version' => 'integer', 'mfa_secret' => 'encrypted', 'mfa_recovery' => 'array', 'mfa_last_step' => 'integer'];
    }
}
