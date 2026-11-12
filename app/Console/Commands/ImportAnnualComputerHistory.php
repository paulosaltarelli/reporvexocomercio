<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AnnualComputerStore;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

class ImportAnnualComputerHistory extends Command
{
    protected $signature = 'vendra:historico-anual {company} {year=2026} {--plan : Somente inspeciona o intervalo e as estimativas} {--confirm-history : Confirma a inclusão do histórico sintético} {--maintenance-confirm : Confirma a janela exclusiva de manutenção e o backup}';

    protected $description = 'Importa histórico anual controlado de informática sem alterar períodos e operações existentes.';

    public function handle(TenantConnection $connection): int
    {
        $year = filter_var($this->argument('year'), FILTER_VALIDATE_INT);
        if ($year === false) {
            $this->error('Informe um ano válido.');
            return self::FAILURE;
        }
        if (! $this->option('plan') && (! $this->option('confirm-history') || ! $this->option('maintenance-confirm'))) {
            $this->error('Use --plan primeiro. Para importar, confirme --confirm-history e --maintenance-confirm após backup e manutenção exclusiva.');
            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', $this->argument('company'))->where('active', true)->firstOrFail();
        try {
            $connection->connect($tenant);
            $service = app(AnnualComputerStore::class);
            if ($this->option('plan')) {
                $this->line(json_encode($service->plan($year), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                return self::SUCCESS;
            }
            $user = User::where('role', 'admin')->where('active', true)->orderBy('id')->firstOrFail();
            $summary = $service->seed($user->id, $year);
            $this->info('Histórico anual concluído para '.$tenant->name.'. Operações anteriores preservadas.');
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } finally {
            $connection->disconnect();
        }
    }
}
