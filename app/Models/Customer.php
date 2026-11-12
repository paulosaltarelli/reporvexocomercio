<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $connection = 'tenant';

    protected $table = 'customers';

    protected $guarded = ['id'];
}
