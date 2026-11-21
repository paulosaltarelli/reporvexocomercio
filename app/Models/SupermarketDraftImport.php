<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupermarketDraftImport extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected function casts(): array { return ['payload' => 'array', 'cart_version' => 'integer']; }
}
