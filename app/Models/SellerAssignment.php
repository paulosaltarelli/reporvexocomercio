<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SellerAssignment extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (): void { throw new \LogicException('O histórico de atribuição de vendedores é imutável.'); });
        static::deleting(function (): void { throw new \LogicException('O histórico de atribuição de vendedores não pode ser excluído.'); });
    }

    public function document() { return $this->belongsTo(Document::class); }
    public function previousSeller() { return $this->belongsTo(Seller::class, 'previous_seller_id'); }
    public function newSeller() { return $this->belongsTo(Seller::class, 'new_seller_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_id'); }
}
