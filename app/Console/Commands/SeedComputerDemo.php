<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\DemoComputerStore;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

class SeedComputerDemo extends Command
{
    protected $signature = 'vendra:demo {company} {--confirm-demo}';

    protected $description = 'Adiciona uma demonstração fictícia de informática, sem apagar cadastros existentes.';

    public function handle(TenantConnection $connection): int
    {
        if (! $this->option('confirm-demo')) {
            $this->error('Confirme --confirm-demo. Os dados serão adicionados à empresa indicada, sem apagar registros.');

            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', $this->argument('company'))->where('active', true)->firstOrFail();
        try {
            $connection->connect($tenant);
            $user = User::where('role', 'admin')->where('active', true)->orderBy('id')->firstOrFail();
            $summary = app(DemoComputerStore::class)->seed($user->id);
            $this->info('Demonstração preparada para '.$tenant->name);
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } finally {
            $connection->disconnect();
        }
    }
}
