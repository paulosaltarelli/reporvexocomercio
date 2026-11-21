<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantOrderPayment extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['payment_snapshot' => 'array', 'refunded_at' => 'datetime']; }
    public function allocations() { return $this->hasMany(RestaurantPaymentItem::class); }
    public function entry() { return $this->belongsTo(FinancialEntry::class, 'financial_entry_id'); }
    public function payment() { return $this->belongsTo(Payment::class); }
    public function share() { return $this->belongsTo(RestaurantOrderShare::class); }
}
