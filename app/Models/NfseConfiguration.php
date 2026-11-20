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

    protected static function booted(): void
    {
        static::saving(fn () => throw new \LogicException('A configuração histórica da DPS é somente leitura.'));
        static::deleting(fn () => throw new \LogicException('Preserve a configuração histórica da DPS.'));
    }
}
