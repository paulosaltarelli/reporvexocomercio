<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NfseConfiguration extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected $hidden = ['secrets'];

    protected function casts(): array
    {
        return ['parameters' => 'array', 'secrets' => 'encrypted:array', 'enabled' => 'boolean', 'national_issuer_confirmed' => 'boolean', 'certificate_valid_until' => 'datetime'];
    }
}
