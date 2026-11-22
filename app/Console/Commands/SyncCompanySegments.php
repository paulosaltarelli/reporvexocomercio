<?php

namespace App\Console\Commands;

use App\Models\CompanyOperation;
use App\Models\Tenant;
use App\Platform\CompanySegment;
use App\Services\TenantConnection;
use Illuminate\Console\Command;

final class SyncCompanySegments extends Command
{
    protected $signature = 'platform:sync-company-segments {--company= : Identificação de uma loja ativa}';
    protected $description = 'Atualiza o segmento das empresas na Central a partir do cadastro de operação de cada loja.';

    public function handle(TenantConnection $connection, CompanySegment $index): int
    {
        $query = Tenant::where('active', true);
        if ($slug = $this->option('company')) {
            $query->where('slug', $slug);
        }
        $updated = 0;
        $failed = 0;
        foreach ($query->lazyById(100) as $tenant) {
            try {
                $connection->connect($tenant);
                $index->sync($tenant->id, CompanyOperation::find(1));
                $updated++;
            } catch (\Throwable) {
                $this->error("O segmento da empresa #{$tenant->id} continua pendente.");
                $failed++;
            } finally {
                $connection->disconnect();
            }
        }
        $this->info("Empresas conferidas: {$updated}.");
        return $failed || ($slug && !$updated) ? self::FAILURE : self::SUCCESS;
    }
}
