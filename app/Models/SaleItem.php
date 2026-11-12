<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $connection = 'tenant';

    protected $table = 'sale_items';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount_allowed' => 'boolean'];
    }
}
