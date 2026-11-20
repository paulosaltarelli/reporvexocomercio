<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessProfile extends Model
{
    protected $connection = 'tenant';

    protected $guarded = ['id'];
    public function logoVersion(): string
    {
        return ($this->logo_updated_at?->timestamp ?? 0).'-'.substr(hash('sha256',(string)$this->logo_path),0,12);
    }

    protected function casts():array{return ['logo_updated_at'=>'datetime'];}
}
