<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupermarketCart extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected $attributes = ['status' => 'active', 'version' => 0, 'total' => '0.00'];

    protected function casts(): array
    {
        return ['items' => 'array', 'version' => 'integer'];
    }
}
