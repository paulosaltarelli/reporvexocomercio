<?php
namespace App\Delivery;
use Illuminate\Database\Eloquent\Model;
final class Order extends Model
{
    protected $connection = 'tenant'; protected $table = 'delivery_orders'; protected $guarded = ['id'];
    protected function casts(): array { return ['simulation'=>'boolean','version'=>'integer','snapshot'=>'array','accepted_at'=>'datetime','completed_at'=>'datetime']; }
    public function items() { return $this->hasMany(OrderItem::class, 'delivery_order_id'); }
    public function events() { return $this->hasMany(Event::class, 'delivery_order_id'); }
}
