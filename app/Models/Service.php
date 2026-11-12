<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Service extends Model
{
    protected $connection = 'tenant';

    protected $table = 'services';

    protected $guarded = ['id'];
}
