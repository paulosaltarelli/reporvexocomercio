<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

class PlatformAdmin extends Model
{
    protected $connection = 'central';
    protected $guarded = ['id'];
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean', 'auth_version' => 'integer'];
    }
}
