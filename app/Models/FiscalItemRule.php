<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalItemRule extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['parameters' => 'array'];
    }
}
