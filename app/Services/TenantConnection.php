<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TenantConnection
{
    public function connect(Tenant $tenant): void
    {
        // A rejected switch must not leave the preceding company's connection available.
        $this->disconnect();

        $shared=$tenant->storage_mode==='shared';
        if (! $tenant->active || ! in_array($tenant->storage_mode,['isolated','shared'],true)) {
            throw new RuntimeException('Empresa indisponível.');
        }
        if ($shared) {
            if (! $tenant->provisioned_at || ! config('tenancy.shared.enabled') || $tenant->db_name!==config('tenancy.shared.database')
                || $tenant->db_host!==config('tenancy.shared.host')
                || ! preg_match('/^vxs_[a-f0-9]{16}_[a-f0-9]{8}$/D',(string)$tenant->db_username)
                || !preg_match('/^[a-f0-9]{64}$/D',(string)config('tenancy.shared.manifest_hash'))) {
                throw new RuntimeException('Empresa indisponível.');
            }
        } elseif (! preg_match('/^vendra_t_[a-z0-9_]+$/D',$tenant->db_name)
            || Tenant::where('db_name',$tenant->db_name)->where('storage_mode','isolated')->when($tenant->id,fn($q)=>$q->where('id','<>',$tenant->id))->exists()) {
            throw new RuntimeException('Empresa indisponível.');
        }
        config(['database.connections.tenant' => [
            'driver' => 'mysql', 'host' => $tenant->db_host, 'port' => $shared?(int)config('tenancy.shared.port'):3306, 'database' => $tenant->db_name,
            'username' => $tenant->db_username, 'password' => $tenant->db_password,
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
            'vendra_tenant_id'=>(int)$tenant->id, 'vendra_storage_mode'=>$tenant->storage_mode,
        ]]);
        if ($shared) {
            try {
                $identities=DB::connection('tenant')->select('SELECT tenant_id,schema_hash,guard_policy_hash FROM __vendra_identity');
                if (count($identities)!==1 || (int)$identities[0]->tenant_id!==(int)$tenant->id
                    || !hash_equals((string)config('tenancy.shared.manifest_hash'),(string)$identities[0]->schema_hash)
                    || !hash_equals(\App\Tenancy\SchemaManifest::guardPolicyHash(),(string)$identities[0]->guard_policy_hash)) {
                    throw new RuntimeException('Invalid tenant identity.');
                }
            } catch (\Throwable) {
                $this->disconnect();
                throw new RuntimeException('Empresa indisponível.');
            }
        }
        config(['tenancy.resolved_tenant_id'=>(int)$tenant->id]);
    }

    /** No SQL read: this check must not open a snapshot before sale/cash row locks. */
    public function matches(Tenant $tenant): bool
    {
        if (!$tenant->active || !$tenant->id || (int)config('tenancy.resolved_tenant_id')!==(int)$tenant->id
            || !is_array(config('database.connections.tenant'))) return false;
        $connection=DB::connection('tenant');
        return $connection->getDatabaseName()===$tenant->db_name
            && $connection->getConfig('username')===$tenant->db_username
            && $connection->getConfig('host')===$tenant->db_host
            && (int)$connection->getConfig('vendra_tenant_id')===(int)$tenant->id
            && $connection->getConfig('vendra_storage_mode')===$tenant->storage_mode
            && ($tenant->storage_mode!=='shared' || $tenant->provisioned_at!==null);
    }

    public function matchesId(int $tenantId): bool
    {
        if($tenantId<1 || (int)config('tenancy.resolved_tenant_id')!==$tenantId
            || !is_array(config('database.connections.tenant'))) return false;
        return (int)DB::connection('tenant')->getConfig('vendra_tenant_id')===$tenantId;
    }

    public function assertTenant(Tenant $tenant): void
    {
        abort_unless($this->matches($tenant),403);
    }

    public function disconnect(): void
    {
        DB::purge('tenant');
        config(['database.connections.tenant' => null,'tenancy.resolved_tenant_id'=>null]);
    }
}
