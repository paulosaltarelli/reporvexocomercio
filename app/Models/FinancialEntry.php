<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialEntry extends Model
{
    protected $connection = 'tenant';

    protected $table = 'financial_entries';

    protected $guarded = ['id'];
}
