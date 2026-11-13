<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalDocument extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];

    protected $hidden = ['snapshot', 'signed_xml'];

    public function sale() { return $this->belongsTo(Sale::class); }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'signed_xml' => 'encrypted', 'authorized_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
