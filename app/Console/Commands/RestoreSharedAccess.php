<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Tenancy\{ControlPlane,SharedManifestFile,SharedSchemaBuilder};
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RestoreSharedAccess extends Command
{
    protected $signature='platform:restore-shared-access {--apply : Restaurar contas e grants após restaurar os backups}';
    protected $description='Reconstrói acessos do banco compartilhado a partir do registry e das credenciais centrais, sem alterar dados de negócio.';
    public function handle(): int
    {
        if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0){$this->error('Execute pelo console root.');return self::FAILURE;}
        $lock=null;
        try {
            $database=(string)config('tenancy.shared.database');
            $companies=Tenant::where('storage_mode','shared')->where('db_name',$database)->orderBy('id')->get();
            $this->info($companies->count().' contas de empresas registradas para esta estrutura.');
            if(!$this->option('apply'))return self::SUCCESS;
            if(is_link('/run/lock/vendra-fiscal-deploy.lock'))throw new \RuntimeException('lock_invalid');
            $lock=fopen('/run/lock/vendra-fiscal-deploy.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('lock_busy');
            foreach(['vendra.service','vendra-fiscal.timer','vendra-fiscal.service'] as $unit) {
                $process=new Process(['/bin/systemctl','is-active',$unit]);$process->run();
                if(trim($process->getOutput())!=='inactive')throw new \RuntimeException('maintenance_required');
            }
            $manifest=SharedManifestFile::load();$builder=new SharedSchemaBuilder(ControlPlane::pdo());
            $builder->restoreDefiner($database,$manifest);
            foreach($companies as $tenant) {
                if($tenant->db_host!==config('tenancy.shared.host'))throw new \RuntimeException('host_mismatch');
                $builder->restoreRuntime($database,(int)$tenant->id,$tenant->db_username,$tenant->db_password,$manifest);
            }
            $this->info('Acessos restaurados e conferidos. Nenhuma senha foi exibida e nenhum dado de negócio foi alterado.');
            return self::SUCCESS;
        } catch(\Throwable) {
            $this->error('Recuperação não concluída. Mantenha a aplicação pausada e confira o backup e o registry pelo console administrativo.');
            return self::FAILURE;
        } finally {if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }
}
