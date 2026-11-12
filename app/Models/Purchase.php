<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Purchase extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['purchased_on' => 'date', 'paid' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function entries()
    {
        return $this->hasMany(FinancialEntry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
