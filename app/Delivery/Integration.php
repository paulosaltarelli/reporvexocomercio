<?php
namespace App\Delivery;
use Illuminate\Database\Eloquent\Model;
final class Integration extends Model
{
    protected $connection = 'tenant'; protected $table = 'delivery_integrations'; protected $guarded = ['id'];
    protected function casts(): array { return ['enabled'=>'boolean', 'manual_test_enabled'=>'boolean', 'version'=>'integer']; }
}
