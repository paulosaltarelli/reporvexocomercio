<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalDocument extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'authorized_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
