<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid' => 'boolean'];
    }

    public function entries()
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
