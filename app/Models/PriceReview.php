<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceReview extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needs_attention' => 'boolean'];
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
