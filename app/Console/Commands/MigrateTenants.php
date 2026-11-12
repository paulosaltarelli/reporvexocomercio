<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

class MigrateTenants extends Command
{
    protected $signature = 'vendra:migrate-tenants {--company=} {--force}';

    protected $description = 'Aplica a mesma estrutura a todos os bancos ativos; interrompe na primeira falha.';

    public function handle(TenantConnection $connection): int
    {
        $query = Tenant::where('active', true);
        if ($this->option('company')) {
            $query->where('slug', $this->option('company'));
        }
        $tenants = $query->orderBy('id')->get();
        if ($tenants->isEmpty()) {
            $this->error('Nenhuma empresa encontrada.');

            return self::FAILURE;
        }
        foreach ($tenants as $tenant) {
            try {
                $connection->connect($tenant);
                $this->info('Atualizando '.$tenant->slug);
                $result = $this->call('migrate', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => $this->option('force')]);
                if ($result !== 0) {
                    return self::FAILURE;
                }
            } finally {
                $connection->disconnect();
            }
        }

        return self::SUCCESS;
    }
}
