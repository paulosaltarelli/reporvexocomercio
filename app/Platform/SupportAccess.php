<?php
namespace App\Platform;

use App\Models\{CompanyOperation,Tenant,User};
use App\Services\{CompanyOperations,TenantConnection};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A central administrator never borrows a customer's identity or shares SQL credentials. */
final class SupportAccess
{
    public static function isManaged(User $user): bool
    {
        return str_ends_with((string)$user->email,'@support.vexo.invalid');
    }
    public function enter(Request $r,int $tenantId): string
    {
        $data=$r->validate(['reason'=>'required|string|min:3|max:1000', 'mfa_code'=>PlatformMfa::required() ? 'nullable|string|max:32' : 'exclude']);
        $actorId=(int)$r->session()->get('platform_admin_id');$version=(int)$r->session()->get('platform_auth_version',-1);
        $verifiedAt=(int)$r->session()->get('platform_mfa_at');
        $needsChallenge=PlatformMfa::needsChallenge($verifiedAt);
        [$tenant,$user]=DB::connection('central')->transaction(function()use($tenantId,$actorId,$version,$data,$needsChallenge){
            $actor=PlatformAdmin::whereKey($actorId)->lockForUpdate()->first();
            abort_unless($actor&&$actor->active&&(int)$actor->auth_version===$version,403);
            if ($needsChallenge && !PlatformMfa::consume($actor, $data['mfa_code'] ?? '')) {
                throw \Illuminate\Validation\ValidationException::withMessages(['mfa_code'=>'Confirme um código atual do autenticador ou um código de recuperação.']);
            }
            $tenant=Tenant::whereKey($tenantId)->where('active',true)->sharedLock()->firstOrFail();
            $connection=app(TenantConnection::class);
            try{
                $connection->connect($tenant);
                $user=DB::connection('tenant')->transaction(function()use($actor){
                    CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>CompanyOperations::DEFAULTS]);
                    $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();$config=$row->configuration;
                    $email='central-'.$actor->id.'@support.vexo.invalid';
                    $user=User::where('email',$email)->lockForUpdate()->first();
                    if(!$user){$user=User::create(['name'=>'Suporte Vexo · '.$actor->name,'email'=>$email,'password'=>Str::random(64),'role'=>'admin','active'=>true,'must_change_password'=>false,'auth_version'=>1]);}
                    else{$user->update(['name'=>'Suporte Vexo · '.$actor->name,'role'=>'admin','active'=>true,'must_change_password'=>false,'auth_version'=>$user->auth_version+1]);}
                    $config['_platform_support'][(string)$actor->id]=$user->id;
                    $row->update(['configuration'=>$config,'version'=>$row->version+1]);
                    return $user;
                },3);
                PlatformAudit::create(['platform_admin_id'=>$actor->id,'tenant_id'=>$tenant->id,'actor_name'=>$actor->name,'action'=>'support.enter','reason'=>$data['reason'],'changes'=>['support_user_id'=>$user->id]]);
                return [$tenant,$user];
            }finally{$connection->disconnect();}
        },3);
        $r->session()->regenerate();
        if ($needsChallenge) $r->session()->put('platform_mfa_at', time());
        $r->session()->put(['tenant_id'=>$tenant->id,'user_id'=>$user->id,'auth_version'=>$user->auth_version,'platform_support'=>['admin_id'=>$actorId,'auth_version'=>$version,'tenant_id'=>$tenant->id,'user_id'=>$user->id,'started_at'=>time()]]);
        return $tenant->slug;
    }
    public function validate(Request $r,User $user): ?PlatformAdmin
    {
        if(!self::isManaged($user) || (PlatformMfa::required() && !$r->session()->get('platform_mfa_at')))return null;
        $context=$r->session()->get('platform_support');$tenant=$r->attributes->get('tenant');
        if(!is_array($context)||($context['tenant_id']??0)!==$tenant->id||($context['user_id']??0)!==$user->id||($context['started_at']??0)<time()-7200)return null;
        if((int)$r->session()->get('platform_admin_id')!==($context['admin_id']??0)||(int)$r->session()->get('platform_auth_version',-1)!==($context['auth_version']??-1))return null;
        $admin=PlatformAdmin::where('active',true)->find($context['admin_id']);
        if(!$admin||(int)$admin->auth_version!==$context['auth_version'])return null;
        $mapping=CompanyOperation::find(1)?->configuration['_platform_support']??[];
        if((int)($mapping[(string)$admin->id]??0)!==$user->id)return null;
        return $admin;
    }
    public function record(Request $r,PlatformAdmin $admin): void
    {
        if(in_array($r->method(),['GET','HEAD','OPTIONS'],true))return;
        PlatformAudit::create(['platform_admin_id'=>$admin->id,'tenant_id'=>$r->attributes->get('tenant')->id,'actor_name'=>$admin->name,'action'=>'support.request','changes'=>['method'=>$r->method(),'route'=>$r->route()->getName()??$r->route()->uri(),'support_user_id'=>$r->attributes->get('currentUser')->id]]);
    }
    public function leave(Request $r): void
    {
        $context=$r->session()->get('platform_support');
        $actor=$r->attributes->get('platformAdmin');
        if(is_array($context))PlatformAudit::create(['platform_admin_id'=>$actor->id,'tenant_id'=>$context['tenant_id']??null,'actor_name'=>$actor->name,'action'=>'support.leave']);
        self::clear($r);
    }
    public static function clear(Request $r): void
    {
        $r->session()->forget(['tenant_id','user_id','auth_version','platform_support']);$r->session()->regenerate();
    }
}
