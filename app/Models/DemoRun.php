<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DemoRun extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['anchor_date' => 'date', 'completed_at' => 'datetime', 'summary' => 'array'];
    }
}
