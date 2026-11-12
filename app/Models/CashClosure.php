<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashClosure extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('Fechamentos de caixa são imutáveis. Registre uma correção identificada em data aberta.');
        });
        static::deleting(function (): void {
            throw new \LogicException('Fechamentos de caixa não podem ser excluídos.');
        });
    }

    protected function casts(): array
    {
        return ['date' => 'date', 'closed_at' => 'datetime', 'snapshot' => 'array'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
