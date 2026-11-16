<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only operational snapshots in the existing tenant-scoped audit ledger. */
class OperationRecord extends Model
{
    protected $connection='tenant';
    protected $table='company_operation_audits';
    public $timestamps=false;
    protected $guarded=['id'];
    protected function casts(): array{return ['before'=>'array','after'=>'array','created_at'=>'datetime'];}
    protected static function booted(): void
    {
        static::updating(fn()=>throw new \LogicException('Registros consolidados são imutáveis.'));
        static::deleting(fn()=>throw new \LogicException('Preserve o histórico consolidado.'));
    }
}
