<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\ControlPlane;
use App\Tenancy\SharedSchemaBuilder;
use App\Tenancy\SharedTenantImporter;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class ImportSharedTenant extends Command
{
    protected $signature = 'vendra:import-shared-tenant {company : Slug da empresa existente} {--manifest= : Manifesto privado já revisado do builder} {--apply : Copiar e trocar conexão; padrão é somente leitura} {--journal= : Journal root com backup.json já validado}';

    protected $description = 'Reconcilia uma fonte isolada e importa por views SQL, preservando IDs e dados; dry-run por padrão.';

    public function handle(): int
    {
        try {
            if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0) throw new RuntimeException('Console root obrigatório.');
            $file = (string) $this->option('manifest');
            if (! str_starts_with($file, '/') || is_link($file) || ! is_file($file) || realpath($file) !== $file || fileowner($file) !== 0 || (fileperms($file) & 0077) !== 0) throw new RuntimeException('Manifesto privado root obrigatório.');
            for ($dir = dirname($file); $dir !== '/'; $dir = dirname($dir)) {
                if (is_link($dir) || realpath($dir) !== $dir || fileowner($dir) !== 0 || (fileperms($dir) & 0022) !== 0) throw new RuntimeException('Ancestral do manifesto inválido.');
            }
            $manifest = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! isset($manifest['tables'], $manifest['migration_sha256'], $manifest['migrations'], $manifest['source_fingerprint'])) throw new RuntimeException('Contrato do manifesto inválido.');
            $tenant = Tenant::where('slug', (string) $this->argument('company'))->firstOrFail();
            $pdo = ControlPlane::pdo();
            $importer = new SharedTenantImporter($pdo, new SharedSchemaBuilder($pdo));
            $result = $importer->run($tenant, $manifest, (bool) $this->option('apply'), $this->option('journal') ?: null);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        } catch (Throwable) {
            // Never print PDO messages, trace arguments, credentials or business rows.
            $this->error('Importação não concluída. Nenhuma retomada automática; confira pré-requisitos e journal privado com o coordenador.');
            return self::FAILURE;
        }
    }
}
