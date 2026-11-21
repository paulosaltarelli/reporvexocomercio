<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantTable extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function orders() { return $this->hasMany(RestaurantOrder::class); }
}
