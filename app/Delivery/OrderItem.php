<?php
namespace App\Delivery;
use Illuminate\Database\Eloquent\Model;
final class OrderItem extends Model
{
    protected $connection = 'tenant'; protected $table = 'delivery_order_items'; protected $guarded = ['id'];
    protected function casts(): array { return ['mapping_snapshot'=>'array','sent_at'=>'datetime']; }
    public function order() { return $this->belongsTo(Order::class, 'delivery_order_id'); }
    public function product() { return $this->belongsTo(\App\Models\Product::class); }
}
