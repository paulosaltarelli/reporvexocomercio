<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean'];
    }
}
