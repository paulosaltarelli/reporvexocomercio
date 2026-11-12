<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'central';

    protected $guarded = ['id'];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return ['db_password' => 'encrypted', 'active' => 'boolean'];
    }
}
