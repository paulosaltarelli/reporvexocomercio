<?php
namespace App\Delivery;
use Illuminate\Database\Eloquent\Model;
final class CatalogMapping extends Model
{
    protected $connection = 'tenant'; protected $table = 'delivery_catalog_mappings'; protected $guarded = ['id'];
    protected function casts(): array { return ['version'=>'integer']; }
    public function product() { return $this->belongsTo(\App\Models\Product::class); }
}
