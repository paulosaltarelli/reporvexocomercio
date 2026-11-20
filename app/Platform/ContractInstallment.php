<?php

namespace App\Platform;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

final class ContractInstallment extends Model
{
    protected $connection = 'central';

    protected $table = 'platform_contract_installments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'due_date' => 'immutable_date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2',
            'cancelled_at' => 'immutable_datetime', 'payment_locked_at' => 'immutable_datetime', 'pix_issue_retry_at' => 'immutable_datetime'];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract()
    {
        return $this->belongsTo(Contract::class);
    }

    public function payments()
    {
        return $this->hasMany(ContractPayment::class, 'installment_id');
    }

    public function getBalanceAttribute(): string
    {
        return $this->cancelled_at ? '0.00' : bcsub($this->amount, $this->paid_amount, 2);
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            bccomp($this->balance, '0', 2) === 0 => 'paid',
            $this->due_date->toDateString() < now()->toDateString() => 'overdue',
            bccomp($this->paid_amount, '0', 2) > 0 => 'partial',
            default => 'pending',
        };
    }
}
