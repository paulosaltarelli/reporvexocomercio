<?php
namespace App\Delivery;
use Illuminate\Database\Eloquent\Model;
final class Event extends Model
{
    protected $connection = 'tenant'; protected $table = 'delivery_events'; protected $guarded = ['id'];
    protected function casts(): array { return ['simulation'=>'boolean','payload'=>'array','attempts'=>'integer','next_attempt_at'=>'datetime','processed_at'=>'datetime']; }
    public function order() { return $this->belongsTo(Order::class, 'delivery_order_id'); }
}
