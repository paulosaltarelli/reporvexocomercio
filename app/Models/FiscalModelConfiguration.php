<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FiscalModelConfiguration extends Model {
    protected $connection = 'tenant';
    protected $guarded = ['id'];
    protected function casts(): array { return ['configuration' => 'array', 'enabled' => 'boolean', 'model' => 'integer']; }
    public function issuer() { return $this->belongsTo(FiscalIssuer::class); }
}
