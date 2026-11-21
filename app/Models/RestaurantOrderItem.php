<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderItem extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['sent_at' => 'datetime', 'commercial_terms' => 'array']; }
    public function order() { return $this->belongsTo(RestaurantOrder::class, 'restaurant_order_id'); }
}
