<?php

namespace App\Platform;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

final class Contract extends Model
{
    protected $connection = 'central';

    protected $table = 'platform_contracts';

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'restricted_modules' => '[]', 'implantation_grace_days' => 0, 'overdue_grace_days' => 0];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'monthly_amount' => 'decimal:2',
            'due_day' => 'integer', 'implantation_grace_days' => 'integer', 'overdue_grace_days' => 'integer', 'restricted_modules' => 'array', 'version' => 'integer'];
    }

    public function billingStartDate(): \Carbon\CarbonImmutable
    {
        return $this->start_date->addDays($this->implantation_grace_days);
    }

    public function inImplantationGrace(): bool
    {
        return $this->implantation_grace_days > 0 && today()->lt($this->billingStartDate());
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function installments()
    {
        return $this->hasMany(ContractInstallment::class, 'contract_id');
    }
}
