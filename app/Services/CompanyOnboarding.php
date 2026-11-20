<?php
namespace App\Services;

use App\Models\{CompanyOperation,Tenant,User};
use Illuminate\Support\Facades\DB;

final class CompanyOnboarding
{
    public function required(Tenant $tenant): bool
    {
        if ($tenant->storage_mode!=='shared'||$tenant->is_demonstration) return false;
        $row=CompanyOperation::find(1);
        return !$row?->completed_at && empty($row?->configuration['_onboarding_completed_at']);
    }
    public function step(): int
    {
        return min(5,max(1,(int)(CompanyOperation::find(1)?->configuration['_setup_step']??1)));
    }
    public function advance(int $next,int $actor): void
    {
        DB::connection('tenant')->transaction(function()use($next,$actor){
            CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>CompanyOperations::DEFAULTS]);
            $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
            InstallerAccess::wizardActor($actor,$row);
            $config=$row->configuration;$before=(int)($config['_setup_step']??1);
            if($before >= $next)return;
            $config['_setup_step']=$next;
            $row->update(['configuration'=>$config,'version'=>$row->version+1,'updated_by'=>$actor]);
            DB::connection('tenant')->table('company_operation_audits')->insert(['version'=>$row->version,'user_id'=>$actor,'action'=>'setup_step','before'=>json_encode(['step'=>$before]),'after'=>json_encode(['step'=>$next]),'created_at'=>now()]);
        },3);
    }
}
