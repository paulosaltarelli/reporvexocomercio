<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Seller extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function user() { return $this->belongsTo(User::class); }
    public function sales() { return $this->hasMany(Sale::class, 'salesperson_id'); }
    public function documents() { return $this->hasMany(Document::class, 'salesperson_id'); }
}
