<?php

namespace App\Platform\Commands;

use App\Platform\ProcessProvisionRequest;
use App\Platform\ProvisionRequest;
use App\Platform\TenantStorageProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class PlatformProvisionCommand extends Command
{
    protected $signature = 'platform:provision {request : Identificador da solicitação pendente}';
    protected $description = 'Processa implantação pelo control plane em terminal root, com senha inicial em prompt oculto.';

    public function handle(): int
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_geteuid') || posix_geteuid() !== 0 || ! $this->input->isInteractive()) {
            $this->error('Execute em terminal interativo como root. Nenhuma implantação foi iniciada.');
            return self::FAILURE;
        }
        if (! ctype_digit((string) $this->argument('request')) || (int) $this->argument('request') < 1) {
            $this->error('Informe o identificador numérico de uma solicitação de implantação.');
            return self::FAILURE;
        }
        if (! app()->bound(TenantStorageProvisioner::class) || ! config('tenancy.shared.enabled')) {
            $this->error('Control plane compartilhado indisponível. Nenhuma solicitação foi processada.');
            return self::FAILURE;
        }
        $lock=null;
        try {
            if(is_link('/run/lock/vendra-fiscal-deploy.lock'))throw new \RuntimeException('lock_invalid');
            $lock=fopen('/run/lock/vendra-fiscal-deploy.lock','c');
            if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new \RuntimeException('lock_busy');
            $job = ProvisionRequest::findOrFail((int) $this->argument('request'));
            if ($job->status === 'completed') {
                $this->info('Solicitação já concluída. Nenhuma senha ou empresa foi alterada.');
                return self::SUCCESS;
            }
            if ($job->status !== 'pending') {
                $this->error('Solicitação não está pendente. Faça uma conferência administrativa; não há repetição automática.');
                return self::FAILURE;
            }
            $password = $this->secret('Senha inicial do administrador do cliente (14 a 72 caracteres, letras e números)', false);
            $confirmation = $this->secret('Confirme a senha inicial', false);
            $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'string', 'min:14', 'max:72', 'confirmed', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/']]);
            if ($validator->fails() || strlen((string) $password) > 72) {
                $this->error('Senhas inválidas ou diferentes. Use 14 a 72 caracteres, com letras e números.');
                return self::FAILURE;
            }
            unset($confirmation, $validator);
            $result = app(ProcessProvisionRequest::class)->process($job->id, $password);
            unset($password);
            if ($result->status !== 'completed') {
                $this->error($result->failure_code === 'cleanup_required' ? 'Implantação falhou e exige conferência de limpeza. Empresa permanece inativa.' : 'Implantação falhou. Empresa permanece inativa; confira a solicitação no painel.');
                return self::FAILURE;
            }
            $this->info('Implantação concluída e empresa ativada. Nenhuma senha será exibida; o administrador deverá trocá-la no primeiro acesso.');
            return self::SUCCESS;
        } catch (\Throwable) {
            // Deliberately omit exception details: infrastructure errors may contain privileged credentials.
            $this->error('Não foi possível concluir a implantação. Confira o status da solicitação antes de repetir; nenhuma credencial será exibida.');
            return self::FAILURE;
        } finally { if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);} }
    }
}

