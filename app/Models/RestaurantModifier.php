<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantModifier extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['active' => 'boolean']; }
    public function group() { return $this->belongsTo(RestaurantModifierGroup::class, 'restaurant_modifier_group_id'); }
}
