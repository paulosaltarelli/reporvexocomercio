<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];
    protected function casts():array{return ['logo_updated_at'=>'datetime'];}
}
