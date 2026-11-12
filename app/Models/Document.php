<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'commercial_version' => 'integer'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function salesperson()
    {
        return $this->belongsTo(Seller::class, 'salesperson_id');
    }

    public function assignments()
    {
        return $this->hasMany(SellerAssignment::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
