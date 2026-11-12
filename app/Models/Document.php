<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'commercial_version' => 'integer'];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
