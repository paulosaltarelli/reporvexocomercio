<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

class PlatformAudit extends Model
{
    protected $connection = 'central';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Auditoria central é imutável.'));
        static::deleting(fn () => throw new \LogicException('Auditoria central deve ser preservada.'));
    }
}
