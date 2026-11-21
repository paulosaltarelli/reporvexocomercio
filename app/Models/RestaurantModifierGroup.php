<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RestaurantModifierGroup extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['active' => 'boolean', 'min_choices' => 'integer', 'max_choices' => 'integer']; }
    public function options() { return $this->hasMany(RestaurantModifier::class); }
}
