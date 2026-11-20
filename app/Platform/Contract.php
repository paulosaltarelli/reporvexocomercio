<?php

namespace App\Platform;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;

final class Contract extends Model
{
    protected $connection = 'central';

    protected $table = 'platform_contracts';

    protected $guarded = ['id'];

    protected $attributes = ['version' => 1, 'restricted_modules' => '[]'];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date', 'end_date' => 'immutable_date', 'monthly_amount' => 'decimal:2',
            'due_day' => 'integer', 'restricted_modules' => 'array', 'version' => 'integer'];
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
