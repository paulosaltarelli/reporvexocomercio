<?php

namespace App\Console\Commands;

use App\Fiscal\ProcessFiscalOutbox;
use App\Models\Tenant;
use App\Services\TenantConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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
        foreach (Tenant::where('active', true)->when($this->option('company'), fn ($q) => $q->where('slug', $this->option('company')))->orderBy('id')->get() as $tenant) {
            try {
                $connection->connect($tenant);
                $ids = DB::connection('tenant')->table('fiscal_outbox')->where('tenant_id', $tenant->id)->whereNull('completed_at')->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id')->all();
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
