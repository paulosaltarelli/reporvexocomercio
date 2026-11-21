<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantPaymentItem extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    public $timestamps = false;
    public function receipt() { return $this->belongsTo(RestaurantOrderPayment::class, 'restaurant_order_payment_id'); }
}
