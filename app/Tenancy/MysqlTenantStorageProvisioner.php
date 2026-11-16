<?php

namespace App\Tenancy;

use App\Models\{BusinessProfile,CompanyOperation,ProductGroup,Tenant,User};
use App\Platform\TenantStorageProvisioner;
use App\Services\{CompanyOperations,TenantConnection};
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/** Root-only control plane adapter. No template customers, products or sales are copied. */
final class MysqlTenantStorageProvisioner implements TenantStorageProvisioner
{
    private SharedSchemaBuilder $builder;
    private array $created=[];

    public function __construct(private PDO $admin, private array $manifest)
    {
        $this->privileged();
        $this->builder=new SharedSchemaBuilder($admin);
        $this->builder->assertExistingSchema((string)config('tenancy.shared.database'),$manifest);
    }

    public function provisionNew(Tenant $tenant,array $adminAttributes,#[\SensitiveParameter]string $password): void
    {
        $this->context($tenant);
        $database=(string)config('tenancy.shared.database');
        $this->builder->assertEmptyNamespace($database,(int)$tenant->id,$this->manifest);
        $this->builder->registerRuntime($database,(int)$tenant->id,$tenant->db_username,$tenant->db_password);
        $this->created[(int)$tenant->id]=$tenant->db_username;
        $connection=app(TenantConnection::class);
        // This clone is used only by this root CLI adapter; no active flag or
        // completion timestamp is persisted before the entire bootstrap succeeds.
        $working=clone $tenant; $working->active=true; $working->provisioned_at=now();
        try {
            $connection->connect($working);
            DB::connection('tenant')->transaction(function () use ($tenant,$adminAttributes,$password) {
                DB::connection('tenant')->table('cash_closure_locks')->insert(['id'=>1]);
                BusinessProfile::forceCreate(['id'=>1,'legal_name'=>$tenant->name]);
                CompanyOperation::forceCreate(['id'=>1,'version'=>0,'configuration'=>array_replace(CompanyOperations::DEFAULTS,[
                    'inventory'=>false,'commissions'=>false,'discounts'=>false,'nfce'=>false,'nfe'=>false,'nfse'=>false,
                ])]);
                ProductGroup::create(['name'=>'Geral','margin'=>'0.00','price_policy'=>'alert','discount_enabled'=>false,'max_discount_percent'=>'0.00','commission_percent'=>'0.00']);
                User::create(['name'=>$adminAttributes['name'],'email'=>$adminAttributes['email'],'password'=>$password,
                    'role'=>'admin','active'=>true,'auth_version'=>0,'must_change_password'=>true]);
            },3);
            $this->builder->assertTenantIntegrity($database,(int)$tenant->id,$this->manifest);
        } finally {$connection->disconnect();}
    }

    public function rollbackNew(Tenant $tenant): void
    {
        $this->context($tenant);$database=(string)config('tenancy.shared.database');
        if (($this->created[(int)$tenant->id]??null)===$tenant->db_username) {
            $this->builder->dropPrincipal($database,$tenant->db_username);
            unset($this->created[(int)$tenant->id]);
        } else {
            $q=$this->admin->prepare("SELECT COUNT(*) FROM mysql.user WHERE User=? AND Host='127.0.0.1'");
            $q->execute([$tenant->db_username]);
            if ((int)$q->fetchColumn()!==0) throw new RuntimeException('principal_requires_reconciliation');
        }
        // Committed rows are quarantined instead of deleting possibly meaningful
        // data after an ambiguous infrastructure result. No other namespace is touched.
        foreach($this->builder->namespaceCounts($database,(int)$tenant->id,$this->manifest) as $count) {
            if($count!==0)throw new RuntimeException('namespace_quarantined_requires_review');
        }
    }

    private function context(Tenant $tenant): void
    {
        $this->privileged();
        if(!$tenant->exists||$tenant->id<1||$tenant->storage_mode!=='shared'||$tenant->active||$tenant->provisioned_at
            ||$tenant->db_name!==config('tenancy.shared.database')||$tenant->db_host!==config('tenancy.shared.host')
            ||!preg_match('/^vxs_'.sprintf('%016x',$tenant->id).'_[a-f0-9]{8}$/D',$tenant->db_username)) {
            throw new RuntimeException('new_tenant_provision_context_invalid');
        }
    }
    private function privileged(): void
    {
        if(PHP_SAPI!=='cli'||!function_exists('posix_geteuid')||posix_geteuid()!==0)throw new RuntimeException('provision_requires_privileged_cli');
    }
}
