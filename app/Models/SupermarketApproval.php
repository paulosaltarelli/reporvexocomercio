<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupermarketApproval extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'cart_version' => 'integer', 'expires_at' => 'datetime',
            'decided_at' => 'datetime', 'applied_at' => 'datetime'];
    }

    public function cart() { return $this->belongsTo(SupermarketCart::class, 'cart_id'); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function supervisor() { return $this->belongsTo(User::class, 'decided_by'); }
}
