<?php

namespace App\Console\Commands;

use App\Tenancy\{ControlPlane,SchemaManifest,SharedManifestFile,SharedSchemaBuilder};
use Illuminate\Console\Command;

class InitializeSharedDatabase extends Command
{
    protected $signature='platform:shared-init {--template= : Base template dedicada aos testes} {--apply : Criar somente um destino novo e vazio}';
    protected $description='Confere o template e prepara a estrutura compartilhada vazia, sem importar nem ativar clientes.';
    public function handle(): int
    {
        if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0){$this->error('Execute no console root.');return self::FAILURE;}
        $template=(string)$this->option('template');
        if(!preg_match('/^vendra_(?:t_test_|shared_template_)[a-z0-9_]+$/D',$template)){$this->error('Informe uma base template dedicada.');return self::FAILURE;}
        $lock=null;
        try {
            $lock=fopen('/run/lock/vendra-fiscal-deploy.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('lock_busy');
            $pdo=ControlPlane::pdo(); $manifest=SchemaManifest::capture($pdo,$template);
            $this->info('Template conferido: '.count($manifest['tables']).' tabelas. Nenhum dado será copiado.');
            if(!$this->option('apply'))return self::SUCCESS;
            if(is_file(SharedManifestFile::PATH))throw new \RuntimeException('manifest_already_exists');
            $result=(new SharedSchemaBuilder($pdo))->build($template,(string)config('tenancy.shared.database'),$manifest);
            SharedManifestFile::save($manifest);
            $this->info('Estrutura vazia criada. Hash de estrutura: '.$result['manifest_hash']);
            $this->info('Nenhuma empresa foi migrada ou ativada.');
            return self::SUCCESS;
        } catch(\Throwable) {
            $this->error('Preparação não concluída. Confira o template, o lock e a configuração administrativa privada. Um destino parcial exige conferência antes de qualquer nova tentativa.');
            return self::FAILURE;
        } finally {if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
    }
}
