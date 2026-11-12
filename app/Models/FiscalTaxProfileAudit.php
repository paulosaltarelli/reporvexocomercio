<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalTaxProfileAudit extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['before_state' => 'array', 'after_state' => 'array', 'summary' => 'array']; }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
    public function profile() { return $this->belongsTo(FiscalTaxProfile::class, 'profile_id'); }
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Auditoria tributária é imutável.'));
        static::deleting(fn () => throw new \LogicException('Auditoria tributária é imutável.'));
    }
}
