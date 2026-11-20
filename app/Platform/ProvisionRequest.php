<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

class ProvisionRequest extends Model
{
    protected $connection = 'central';
    protected $table = 'platform_provision_requests';
    protected $hidden = ['activation_digest'];
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_demonstration' => 'boolean', 'started_at' => 'datetime', 'activation_expires_at' => 'datetime', 'activation_retry_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
