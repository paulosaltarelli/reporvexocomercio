<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalCredential extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['secrets' => 'encrypted:array', 'valid_from' => 'datetime', 'valid_until' => 'datetime'];
    }
}
