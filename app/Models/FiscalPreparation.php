<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class FiscalPreparation extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'blockers' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Conferência fiscal é imutável; crie uma nova revisão.'));
        static::deleting(fn () => throw new LogicException('Conferência fiscal deve preservar o histórico.'));
    }
}
