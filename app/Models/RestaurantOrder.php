<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrder extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['version' => 'integer', 'closed_at' => 'datetime']; }
    public function table() { return $this->belongsTo(RestaurantTable::class, 'restaurant_table_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function items() { return $this->hasMany(RestaurantOrderItem::class); }
    public function sale() { return $this->belongsTo(Sale::class); }
}
