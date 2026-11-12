<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSimulationEvent extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function simulation() { return $this->belongsTo(FiscalSimulation::class, 'simulation_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Eventos de simulação são imutáveis.'));
        static::deleting(fn () => throw new \LogicException('Eventos de simulação são imutáveis.'));
    }
}
