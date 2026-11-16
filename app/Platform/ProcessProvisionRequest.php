<?php

namespace App\Platform;

use App\Fiscal\Snapshot;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Synchronous privileged worker. A crashed provisioning job is never retried automatically. */
final class ProcessProvisionRequest
{
    public function __construct(private readonly TenantStorageProvisioner $storage) {}

    public function process(int $requestId, #[\SensitiveParameter] string $password): ProvisionRequest
    {
        $this->privileged();
        if (strlen($password) > 72) {
            throw ValidationException::withMessages(['password' => 'A senha inicial deve ter no máximo 72 bytes para armazenamento seguro.']);
        }
        Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:14', 'max:72', 'regex:/[a-zA-Z]/', 'regex:/[0-9]/']])->validate();
        if (! config('tenancy.shared.enabled')) {
            throw ValidationException::withMessages(['storage' => 'Implantação compartilhada ainda não habilitada. Nenhuma solicitação foi processada.']);
        }
        $database = (string) config('tenancy.shared.database');
        $host = (string) config('tenancy.shared.host');
        if (! preg_match('/^vendra_shared(?:_[a-z0-9_]+)?$/D', $database) || $host === '') {
            throw ValidationException::withMessages(['storage' => 'Configuração de armazenamento compartilhado inválida.']);
        }
        $claimed = DB::connection('central')->transaction(function () use ($requestId, $database, $host) {
            $job = ProvisionRequest::whereKey($requestId)->lockForUpdate()->firstOrFail();
            if ($job->status === 'completed') {
                return ['job' => $job, 'tenant' => null];
            }
            if ($job->status !== 'pending' || $job->tenant_id || $job->started_at) {
                throw ValidationException::withMessages(['request' => 'Solicitação já processada ou em processamento. Faça uma conferência administrativa antes de qualquer nova tentativa.']);
            }
            $actor = PlatformAdmin::whereKey($job->requested_by)->lockForUpdate()->first();
            abort_unless($actor && $actor->active, 403);
            $payload = $this->payload($job);
            if (! hash_equals($job->payload_sha256, Snapshot::hash($payload)) || $job->active_slug !== $job->slug) {
                throw ValidationException::withMessages(['request' => 'Integridade ou reserva da solicitação inválida.']);
            }
            if (Tenant::where('slug', $job->slug)->exists()) {
                throw ValidationException::withMessages(['slug' => 'Já existe uma empresa com este identificador. Nenhum cadastro existente foi alterado.']);
            }
            $tenant = Tenant::create([
                'name' => $job->name, 'slug' => $job->slug, 'active' => false,
                'storage_mode' => 'shared', 'is_demonstration' => $job->is_demonstration,
                'fiscal_demonstration' => true, 'platform_version' => 0, 'provisioned_at' => null,
                'db_host' => $host, 'db_name' => $database,
                'db_username' => 'pending_'.bin2hex(random_bytes(8)),
                'db_password' => bin2hex(random_bytes(24)),
            ]);
            if ($tenant->id < 1 || $tenant->id > PHP_INT_MAX) {
                throw new \RuntimeException('unsupported_tenant_identifier');
            }
            $tenant->db_username = 'vxs_'.str_pad(dechex((int) $tenant->id), 16, '0', STR_PAD_LEFT).'_'.bin2hex(random_bytes(4));
            $tenant->save();
            $job->status = 'provisioning';
            $job->tenant_id = $tenant->id;
            $job->started_at = now();
            $job->failure_code = null;
            $job->save();
            PlatformAudit::create(['platform_admin_id' => $actor->id, 'tenant_id' => $tenant->id, 'provision_request_id' => $job->id, 'actor_name' => 'Worker administrativo', 'action' => 'company.provision_started', 'changes' => ['status' => 'provisioning', 'active' => false]]);
            return ['job' => $job, 'tenant' => $tenant];
        }, 3);
        if (! $claimed['tenant']) {
            return $claimed['job'];
        }
        $tenant = $claimed['tenant'];
        $job = $claimed['job'];
        try {
            $this->storage->provisionNew($tenant, ['name' => $job->admin_name, 'email' => $job->admin_email, 'must_change_password' => true], $password);
            return DB::connection('central')->transaction(function () use ($tenant, $job) {
                $current = ProvisionRequest::whereKey($job->id)->lockForUpdate()->firstOrFail();
                $actor = PlatformAdmin::whereKey($current->requested_by)->lockForUpdate()->first();
                $company = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                if (! $actor || ! $actor->active || $current->status !== 'provisioning' || (int) $current->tenant_id !== (int) $company->id
                    || $company->active || $company->provisioned_at || $company->storage_mode !== 'shared'
                    || $company->slug !== $job->slug || $company->db_username !== $tenant->db_username) {
                    throw new \RuntimeException('provision_context_changed');
                }
                $company->provisioned_at = now();
                $company->active = true;
                $company->platform_version = (int) $company->platform_version + 1;
                $company->save();
                $current->status = 'completed';
                $current->active_slug = null;
                $current->completed_at = now();
                $current->failure_code = null;
                $current->save();
                PlatformAudit::create(['platform_admin_id' => $actor->id, 'tenant_id' => $company->id, 'provision_request_id' => $current->id, 'actor_name' => 'Worker administrativo', 'action' => 'company.provision_completed', 'changes' => ['status' => 'completed', 'active' => true]]);
                return $current;
            }, 3);
        } catch (\Throwable) {
            // A commit can succeed before a connection failure. Never remove storage on an unknown result.
            try {
                $probe = ProvisionRequest::findOrFail($job->id);
                $probeCompany = Tenant::findOrFail($tenant->id);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['request' => 'Resultado da implantação não pôde ser conferido. Nenhuma limpeza foi tentada; solicite conferência administrativa antes de repetir.']);
            }
            if ($probe->status === 'completed' && (int) $probe->tenant_id === (int) $tenant->id && $probeCompany->active && $probeCompany->provisioned_at) {
                return $probe;
            }
            if ($probe->status !== 'provisioning' || (int) $probe->tenant_id !== (int) $tenant->id
                || $probeCompany->active || $probeCompany->provisioned_at || $probeCompany->storage_mode !== 'shared'
                || $probeCompany->slug !== $job->slug || $probeCompany->db_username !== $tenant->db_username) {
                throw ValidationException::withMessages(['request' => 'O contexto da implantação mudou. Nenhuma limpeza foi tentada; faça uma conferência administrativa.']);
            }
            // Never serialize/log adapter exceptions: they may contain SQL, passwords or privileged credentials.
            $rollbackSucceeded = false;
            try {
                $this->storage->rollbackNew($tenant);
                $rollbackSucceeded = true;
            } catch (\Throwable) {
                // Keep a sanitized recovery flag. Never broaden cleanup to existing namespaces.
            }
            return DB::connection('central')->transaction(function () use ($tenant, $job, $rollbackSucceeded) {
                $current = ProvisionRequest::whereKey($job->id)->lockForUpdate()->firstOrFail();
                $company = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $company->active = false;
                $company->provisioned_at = null;
                $company->platform_version = (int) $company->platform_version + 1;
                $company->save();
                $current->status = 'failed';
                $current->active_slug = null;
                $current->failure_code = $rollbackSucceeded ? 'provision_failed' : 'cleanup_required';
                $current->completed_at = now();
                $current->save();
                PlatformAudit::create(['platform_admin_id' => $current->requested_by, 'tenant_id' => $company->id, 'provision_request_id' => $current->id, 'actor_name' => 'Worker administrativo', 'action' => 'company.provision_failed', 'changes' => ['status' => 'failed', 'active' => false, 'failure_code' => $current->failure_code]]);
                return $current;
            }, 3);
        }
    }

    private function payload(ProvisionRequest $job): array
    {
        return ['name' => $job->name, 'slug' => $job->slug, 'admin_name' => $job->admin_name, 'admin_email' => $job->admin_email, 'is_demonstration' => (bool) $job->is_demonstration];
    }

    private function privileged(): void
    {
        abort_unless(PHP_SAPI === 'cli' && function_exists('posix_geteuid') && posix_geteuid() === 0, 403);
    }
}
