<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductGroup extends Model
{
    protected $connection = 'tenant';

    protected $table = 'product_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['discount_enabled' => 'boolean'];
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
