<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'central';

    protected $guarded = ['id'];

    protected $attributes = ['storage_mode'=>'isolated','is_demonstration'=>false,'platform_version'=>0];

    protected $hidden = ['db_password'];

    protected function casts(): array
    {
        return ['db_password' => 'encrypted', 'active' => 'boolean', 'is_demonstration'=>'boolean', 'platform_version'=>'integer', 'provisioned_at'=>'datetime', 'fiscal_demonstration' => 'boolean', 'fiscal_production_approved_at' => 'datetime'];
    }
}
