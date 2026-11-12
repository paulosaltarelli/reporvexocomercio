<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSetting extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['nfce_accredited' => 'boolean', 'configuration_draft' => 'array'];
    }
}
