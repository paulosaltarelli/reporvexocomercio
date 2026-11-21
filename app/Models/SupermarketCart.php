<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupermarketCart extends Model
{
    /** Response metadata for the successful scan, never persisted as a model attribute. */
    public ?string $lastScannedLineKey = null;

    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected $attributes = ['status' => 'active', 'version' => 0, 'total' => '0.00'];

    protected function casts(): array
    {
        return ['items' => 'array', 'version' => 'integer'];
    }
}
