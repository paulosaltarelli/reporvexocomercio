<?php

namespace App\Console\Commands;

use App\Fiscal\ProcessFiscalOutbox;
use App\Fiscal\ProductInvoiceGate;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

class ProcessFiscal extends Command
{
    protected $signature = 'vendra:fiscal-work {--company=} {--limit=20}';

    protected $description = 'Processa outbox fiscal por empresa; não depende da fila síncrona.';

    public function handle(TenantConnection $connection, ProcessFiscalOutbox $worker): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if (! $limit) {
            return self::FAILURE;
        }
        $pilots = ProductInvoiceGate::pilotTenantIds();
        $global = ProductInvoiceGate::globallyEnabled();
        if (! $global && ! $pilots) {
            return self::SUCCESS;
        }
        $tenants = Tenant::where('active', true)
            ->when(! $global, fn ($q) => $q->whereIn('id', $pilots))
            ->when($this->option('company') !== null, fn ($q) => $q->where('slug', $this->option('company')));
        foreach ($tenants->orderBy('id')->get() as $tenant) {
            try {
                $connection->connect($tenant);
                $ids = $worker->pendingJobs($tenant)->orderBy('id')->limit($limit)->pluck('id')->all();
            } finally {
                $connection->disconnect();
            }
            foreach ($ids as $id) {
                $worker->run($tenant->id, $id);
            }
        }
        $this->info('Lote fiscal processado.');

        return self::SUCCESS;
    }
}
