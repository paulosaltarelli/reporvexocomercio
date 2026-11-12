<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChange extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('O histórico de preços é imutável. Registre uma nova alteração.');
        });
        static::deleting(function (): void {
            throw new \LogicException('O histórico de preços não pode ser excluído.');
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
