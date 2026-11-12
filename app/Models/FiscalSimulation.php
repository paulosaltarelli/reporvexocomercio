<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalSimulation extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'consulted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $simulation) {
            foreach (['tenant_id', 'sale_id', 'requested_by', 'reference', 'request_key', 'scenario', 'snapshot', 'snapshot_sha256'] as $field) {
                if ($simulation->isDirty($field)) {
                    throw new \LogicException('O retrato original da simulação é imutável.');
                }
            }
        });
    }

    public function sale() { return $this->belongsTo(Sale::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function events() { return $this->hasMany(FiscalSimulationEvent::class, 'simulation_id')->orderBy('id'); }
}
