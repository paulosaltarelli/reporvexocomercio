<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalTaxProfile extends Model
{
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['parameters' => 'array', 'active' => 'boolean', 'revision' => 'integer', 'validated_at' => 'datetime']; }
    public function validator() { return $this->belongsTo(User::class, 'validated_by'); }
    public function products() { return $this->hasMany(Product::class, 'fiscal_tax_profile_id'); }
    public function groups() { return $this->hasMany(ProductGroup::class, 'fiscal_tax_profile_id'); }
}
