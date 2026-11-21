<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantProductConfig extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['recipe' => 'array', 'revision' => 'integer', 'show_on_menu' => 'boolean']; }
    public function product() { return $this->belongsTo(Product::class); }
}
