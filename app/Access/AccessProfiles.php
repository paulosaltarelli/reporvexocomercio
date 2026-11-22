<?php
namespace App\Access;

use App\Models\{CompanyOperation,User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule,ValidationException};

/** Tenant-scoped policy in the existing protected company configuration; no schema change. */
final class AccessProfiles
{
    private const KEY='_access_profiles';
    public const PRESETS = [
        'supermarket_cashier' => ['name'=>'Caixa de supermercado', 'permissions'=>['supermarket.use','supermarket.checkout']],
        'supermarket_supervisor' => ['name'=>'Supervisor de supermercado', 'permissions'=>['supermarket.use','supermarket.checkout','supermarket.cancel','supermarket.supervise']],
        'waiter' => ['name'=>'Garçom', 'permissions'=>['restaurant.use','restaurant.deliver','restaurant.transfer','restaurant.print']],
        'kitchen' => ['name'=>'Cozinha', 'permissions'=>['restaurant.kitchen','restaurant.print']],
        'restaurant_cashier' => ['name'=>'Caixa de restaurante', 'permissions'=>['restaurant.use','restaurant.checkout','restaurant.receive']],
    ];
    public function state(bool $fresh=false): array
    {
        $db=DB::connection('tenant');
        $cache='vendra.access.'.hash('sha256',(string)$db->getConfig('username').'|'.$db->getDatabaseName());
        $request=app('request');
        if (!$fresh && !$db->transactionLevel() && $request->attributes->has($cache)) return $request->attributes->get($cache);
        $row=CompanyOperation::find(1);
        $state=$this->fromRow($row);
        if (!$db->transactionLevel()) $request->attributes->set($cache,$state);
        return $state;
    }
    private function fromRow(?CompanyOperation $row): array
    {
        $stored=$row?->configuration[self::KEY]??null;
        $default=['profiles'=>['operator'=>['name'=>'Operador padrão','permissions'=>Permissions::expand(Permissions::OPERATOR)]],'assignments'=>[]];
        if($stored!==null && (!is_array($stored)||!is_array($stored['profiles']??null)||!is_array($stored['assignments']??null)))throw new \RuntimeException('Configuração de perfis inválida.');
        $state=['version'=>(int)($row?->version??0),'profiles'=>$stored===null?$default['profiles']:$stored['profiles'],'assignments'=>$stored===null?[]:$stored['assignments']];
        foreach($state['profiles'] as $profile){
            if(!is_array($profile)||!is_string($profile['name']??null)||!is_array($profile['permissions']??null)||array_diff($profile['permissions'],array_keys(Permissions::labels())))throw new \RuntimeException('Configuração de permissões inválida.');
        }
        foreach($state['assignments'] as $profile)if(!is_string($profile))throw new \RuntimeException('Vínculo de perfil inválido.');
        return $state;
    }
    private function clear(): void
    {
        foreach(array_keys(app('request')->attributes->all()) as $key)if(str_starts_with($key,'vendra.access.'))app('request')->attributes->remove($key);
    }
    public function allows(User $user,string $permission): bool
    {
        if (!$user->active) return false;
        if ($user->role===User::ROLE_INSTALLER) return $permission==='account.self';
        if ($permission==='account.self') return true;
        if ($permission==='commissions.self') return \App\Models\Seller::where('user_id',$user->id)->exists();
        if ($permission==='denied'||(!array_key_exists($permission,Permissions::labels())&&!in_array($permission,['administration','home'],true))) return false;
        if ($user->role==='admin') return true;
        if ($permission==='administration') return false;
        if ($permission==='home') return !$this->operationalOnly($user);
        return in_array($permission,$this->permissions($user),true);
    }
    public function operationalOnly(User $user): bool
    {
        if ($user->role !== 'operator') return false;
        $permissions = $this->permissions($user);
        $operational = array_filter($permissions, fn ($permission) => str_starts_with($permission,'restaurant.') || str_starts_with($permission,'supermarket.') || str_starts_with($permission,'delivery.'));
        return (bool)$operational && !array_diff($permissions, [...$operational,'account.self']);
    }
    public function profileId(int $userId): string { return (string)($this->state()['assignments'][(string)$userId]??'operator'); }
    public function permissions(User $user): array
    {
        if (!$user->active || $user->role===User::ROLE_INSTALLER) return [];
        if ($user->role==='admin') return array_keys(Permissions::labels());
        $state=$this->state();$id=(string)($state['assignments'][(string)$user->id]??'operator');
        return Permissions::expand($state['profiles'][$id]['permissions']??[]);
    }
    /** Supervisor ceiling; product/group rules remain an independent upper bound. */
    public function discountLimit(User $user): string
    {
        if (!$user->active || !$user->allows('supermarket.supervise')) return '0.00';
        if ($user->role === 'admin') return '100.00';
        $state = $this->state();
        $value = (string)($state['profiles'][$this->profileId($user->id)]['max_discount_percent'] ?? '100');
        if (!preg_match('/^\d{1,3}(?:\.\d{1,2})?$/D', $value) || bccomp($value,'100',2)>0) return '0.00';
        return bcadd($value,'0',2);
    }
    public function profileName(User $user): string
    {
        if ($user->role===User::ROLE_INSTALLER) return 'Implantador';
        if ($user->role==='admin') return 'Administrador — acesso completo';
        $state=$this->state();return $state['profiles'][$this->profileId($user->id)]['name']??'Perfil indisponível — sem funções liberadas';
    }
    public function authorize(Request $request): void
    {
        $user=$request->attributes->get('currentUser');
        if ($user?->role===User::ROLE_INSTALLER) {
            abort_unless(\App\Services\InstallerAccess::permits($request),403);
            return;
        }
        $mode = match (class_basename($request->route()?->getControllerClass() ?? '')) {
            'DeskController' => 'commerce',
            'RestaurantController' => 'restaurant',
            'SupermarketController', 'SupermarketApprovalController', 'SupermarketDraftController', 'SupermarketHardwareController', 'SupermarketShiftController' => 'supermarket',
            'RestaurantActivityController', 'RestaurantCatalogController', 'RestaurantPaymentController', 'DeliveryController' => 'restaurant',
            default => null,
        };
        if ($mode !== null) abort_unless(app(\App\Services\OperationalModes::class)->enabled($mode), 403, 'Este atendimento está desabilitado para a empresa.');
        abort_unless($user && $this->allows($user,Permissions::request($request)),403,'Seu perfil não libera esta função.');
    }
    public function landing(User $user): string
    {
        if ($user->role===User::ROLE_INSTALLER) return \App\Services\InstallerAccess::open() ? 'setup' : 'installer.home';
        if ($route = app(\App\Services\OperationalModes::class)->landing($user)) return $route;
        foreach(['desk'=>'desk.use','dashboard'=>'dashboard.view','sales'=>'sales.view','finance'=>'finance.view','stock'=>'stock.view','products'=>'products.view','reports'=>'reports.view'] as $route=>$permission)if(($route !== 'desk' || app(\App\Services\OperationalModes::class)->enabled('commerce')) && $this->allows($user,$permission))return $route;
        if($this->allows($user,'commissions.self'))return 'commissions.mine';
        return 'account.password';
    }
    private function admin(int $actor): void { abort_unless(\App\Services\InstallerAccess::adminActor($actor),403); }
    private function locked(int $version): CompanyOperation
    {
        CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>\App\Services\CompanyOperations::DEFAULTS]);
        $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
        if($row->version!==$version)throw ValidationException::withMessages(['version'=>'Os acessos mudaram. Recarregue antes de salvar.']);
        return $row;
    }
    private function persist(CompanyOperation $row,array $state,int $actor,string $action,array $before,array $after): void
    {
        $config=$row->configuration;$config[self::KEY]=['profiles'=>$state['profiles'],'assignments'=>$state['assignments']];
        $row->update(['configuration'=>$config,'version'=>$row->version+1,'updated_by'=>$actor]);
        DB::connection('tenant')->table('company_operation_audits')->insert(['version'=>$row->version,'user_id'=>$actor,'action'=>$action,'before'=>json_encode($before,JSON_THROW_ON_ERROR),'after'=>json_encode($after,JSON_THROW_ON_ERROR),'created_at'=>now()]);
        $this->clear();
    }
    public function save(array $input,int $actor,?string $id=null): string
    {
        $this->admin($actor);
        if(is_string($input['name']??null))$input['name']=trim($input['name']);
        $data=Validator::make($input,['version'=>'required|integer|min:0','name'=>'required|string|max:80','permissions'=>'present|array|max:100','permissions.*'=>['string','distinct',Rule::in(array_keys(Permissions::labels()))],'max_discount_percent'=>'nullable|numeric|min:0|max:100|decimal:0,2'])->validate();
        $permissions=Permissions::expand($data['permissions']);
        return DB::connection('tenant')->transaction(function()use($data,$permissions,$actor,$id){
            $row=$this->locked((int)$data['version']);$state=$this->fromRow($row);
            if($id!==null&&!isset($state['profiles'][$id]))abort(404);
            foreach($state['profiles'] as $key=>$profile)if($key!==$id&&mb_strtolower($profile['name'])===mb_strtolower($data['name']))throw ValidationException::withMessages(['name'=>'Já existe um perfil com este nome.']);
            $key=$id??(string)Str::uuid();$before=$state['profiles'][$key]??[];$after=['name'=>$data['name'],'permissions'=>$permissions];
            if(isset($data['max_discount_percent']))$after['max_discount_percent']=bcadd((string)$data['max_discount_percent'],'0',2);
            elseif(isset($before['max_discount_percent']))$after['max_discount_percent']=$before['max_discount_percent'];
            if($before===$after)return $key;
            $state['profiles'][$key]=$after;$this->persist($row,$state,$actor,'access_profile_saved',['id'=>$key]+$before,['id'=>$key]+$after);
            return $key;
        },3);
    }
    public function delete(string $id,int $version,int $actor): void
    {
        $this->admin($actor);
        DB::connection('tenant')->transaction(function()use($id,$version,$actor){
            $row=$this->locked($version);$state=$this->fromRow($row);abort_unless(isset($state['profiles'][$id]),404);
            if($id==='operator')throw ValidationException::withMessages(['profile'=>'O perfil padrão não pode ser excluído.']);
            if(in_array($id,$state['assignments'],true))throw ValidationException::withMessages(['profile'=>'Reatribua os usuários deste perfil antes de excluí-lo.']);
            $before=$state['profiles'][$id];unset($state['profiles'][$id]);$this->persist($row,$state,$actor,'access_profile_deleted',['id'=>$id]+$before,[]);
        },3);
    }
    /** Must run inside the same transaction that creates/updates the user. */
    public function assign(int $userId,string $role,string $profileId,int $actor): bool
    {
        $this->admin($actor);
        abort_unless(in_array($role,['admin','operator'],true)&&User::whereKey($userId)->where('role','<>',User::ROLE_INSTALLER)->exists(),422);
        if(DB::connection('tenant')->transactionLevel()===0)throw new \LogicException('Atribuição exige transação de usuário.');
        CompanyOperation::firstOrCreate(['id'=>1],['configuration'=>\App\Services\CompanyOperations::DEFAULTS]);
        $row=CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();$state=$this->fromRow($row);
        if($role==='operator'&&!isset($state['profiles'][$profileId]))throw ValidationException::withMessages(['access_profile'=>'Selecione um perfil válido desta empresa.']);
        if($role==='admin'&&!isset($state['assignments'][(string)$userId]))return false;
        $before=$state['assignments'][(string)$userId]??'operator';
        if($role==='admin')unset($state['assignments'][(string)$userId]);else $state['assignments'][(string)$userId]=$profileId;
        $after=$role==='admin'?'administrator':$profileId;
        if($before===$after)return false;
        $this->persist($row,$state,$actor,'access_profile_assigned',['user_id'=>$userId,'profile'=>$before],['user_id'=>$userId,'profile'=>$after]);
        return true;
    }
}
