<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantConnection
{
    public function connect(Tenant $tenant): void
    {
        // A rejected switch must not leave the preceding company's connection available.
        $this->disconnect();

        if (! $tenant->active || ! preg_match('/^vendra_t_[a-z0-9_]+$/D', $tenant->db_name)) {
            throw new RuntimeException('Empresa indisponível.');
        }
        config(['database.connections.tenant' => [
            'driver' => 'mysql', 'host' => $tenant->db_host, 'port' => 3306, 'database' => $tenant->db_name,
            'username' => $tenant->db_username, 'password' => $tenant->db_password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
        ]]);
    }

    public function disconnect(): void
    {
        DB::purge('tenant');
        config(['database.connections.tenant' => null]);
    }
}
