<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderShare extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['cancelled_at' => 'datetime']; }
    public function receipts() { return $this->hasMany(RestaurantOrderPayment::class, 'share_id'); }
}
