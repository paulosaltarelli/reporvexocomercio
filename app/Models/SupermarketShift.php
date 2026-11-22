<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupermarketShift extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'terminal_number' => 'integer',
            'sale_count' => 'integer',
            'received_by_method' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
