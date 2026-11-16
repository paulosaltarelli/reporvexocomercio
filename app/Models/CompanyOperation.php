<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CompanyOperation extends Model
{
    protected $connection = 'tenant';
    public $incrementing = false;
    protected $guarded = []; // Only validated domain data is assigned by CompanyOperations.
    protected function casts(): array { return ['configuration'=>'array', 'completed_at'=>'datetime', 'version'=>'integer']; }
}
