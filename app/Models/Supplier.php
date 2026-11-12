<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $connection = 'tenant';

    protected $table = 'suppliers';

    protected $guarded = ['id'];
}
