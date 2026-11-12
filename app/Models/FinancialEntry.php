<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialEntry extends Model
{
    protected $connection = 'tenant';

    protected $table = 'financial_entries';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'legacy_import' => 'boolean'];
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'entry_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function getBalanceAttribute(): string
    {
        return $this->cancelled_at ? '0.00' : bcsub($this->amount, $this->paid_amount, 2);
    }
}
