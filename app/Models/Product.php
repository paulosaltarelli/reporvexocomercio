<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $connection = 'tenant';

    protected $table = 'products';

    protected $guarded = ['id'];

    public function group()
    {
        return $this->belongsTo(ProductGroup::class, 'product_group_id');
    }
}
