<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['paid_on' => 'date'];
    }

    public function entry()
    {
        return $this->belongsTo(FinancialEntry::class, 'entry_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function original()
    {
        return $this->belongsTo(self::class, 'reversal_of');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of');
    }
}
