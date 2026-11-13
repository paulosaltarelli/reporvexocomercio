<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NfseDocument extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected $hidden = ['signed_xml', 'snapshot'];

    protected function casts(): array
    {
        return ['signed_xml' => 'encrypted', 'snapshot' => 'array', 'competence' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('A DPS congelada é imutável.'));
        static::deleting(fn () => throw new \LogicException('Preserve o histórico e a numeração da DPS.'));
    }
}
