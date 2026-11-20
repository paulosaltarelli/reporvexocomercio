<?php

namespace App\Platform\Commands;

use App\Platform\CompanyActivation;
use App\Platform\PlatformAdmin;
use App\Platform\ProcessProvisionRequest;
use App\Platform\ProvisionRequest;
use App\Platform\TenantStorageProvisioner;
use App\Models\Tenant;
use App\Tenancy\CompanyHosts;
use Illuminate\Console\Command;

final class PlatformProvisionWorkCommand extends Command
{
    protected $signature = 'platform:provision-work';
    protected $description = 'Processa a fila de implantação compartilhada e entrega os convites de ativação.';

    public function handle(): int
    {
        if (!app()->bound(TenantStorageProvisioner::class) || !config('tenancy.shared.enabled') || CompanyHosts::domain() === ''
            || config('mail.mailers.'.config('mail.default').'.transport') !== 'smtp') {
            $this->error('O worker requer control plane, domínio das lojas e SMTP configurados.');
            return self::FAILURE;
        }
        $lock = null;
        try {
            $path = '/run/lock/vendra-fiscal-deploy.lock';
            if (is_link($path)) throw new \RuntimeException('invalid_lock');
            $lock = fopen($path, 'c');
            if (!$lock) throw new \RuntimeException('missing_lock');
            if (!flock($lock, LOCK_EX | LOCK_NB)) return self::SUCCESS;
            $failed = false;
            $jobs = ProvisionRequest::where('status', 'pending')
                ->whereIn('requested_by', PlatformAdmin::where('active', true)->select('id'))->orderBy('id')->limit(10)->get();
            foreach ($jobs as $job) {
                try {
                    if (CompanyHosts::reserved($job->slug)) {
                        $this->warn("Pedido {$job->id} exige revisão do identificador reservado.");
                        $failed = true;
                        continue;
                    }
                    $result = app(ProcessProvisionRequest::class)->process($job->id, 'V1'.bin2hex(random_bytes(32)), true);
                    if ($result->status !== 'completed') $failed = true;
                } catch (\Throwable) {
                    $failed = true;
                    $this->error("Confira o estado do pedido {$job->id} na central.");
                }
            }
            $deliveries = ProvisionRequest::where('activation_status', 'queued')->where('status', 'completed')
                ->whereIn('tenant_id', Tenant::where('active', true)->select('id'))
                ->where(fn ($q) => $q->whereNull('activation_retry_at')->orWhere('activation_retry_at', '<=', now()))
                ->orderBy('id')->limit(20)->pluck('id');
            foreach ($deliveries as $id) {
                try {
                    app(CompanyActivation::class)->send($id);
                } catch (\Throwable) {
                    $this->error("Entrega do convite {$id} pendente. Confira o serviço de e-mail.");
                    $failed = true;
                }
            }
            return $failed ? self::FAILURE : self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Falha ao executar o worker. Confira a configuração e a fila.');
            return self::FAILURE;
        } finally {
            if (is_resource($lock)) fclose($lock);
        }
    }
}
