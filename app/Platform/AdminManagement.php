<?php
namespace App\Platform;

use Illuminate\Support\Facades\{DB,Validator};
use Illuminate\Validation\{Rule,ValidationException};

final class AdminManagement
{
    public function save(array $input,int $actorId,int $actorVersion,?int $id=null): PlatformAdmin
    {
        abort_if($id!==null&&$id<1,404);
        if(is_string($input['email']??null))$input['email']=mb_strtolower(trim($input['email']));
        if(is_string($input['name']??null))$input['name']=trim($input['name']);
        $data=Validator::make($input,['name'=>'required|string|min:2|max:180','email'=>['required','email','max:180',Rule::unique('central.platform_admins','email')->ignore($id)],'active'=>'required|boolean','expected_version'=>$id?'required|integer|min:0':'nullable', 'password'=>[$id?'nullable':'required','string','min:14','max:72','confirmed','regex:/[a-zA-Z]/','regex:/[0-9]/']])->validate();
        return DB::connection('central')->transaction(function()use($data,$actorId,$actorVersion,$id){
            $admins=PlatformAdmin::orderBy('id')->lockForUpdate()->get();
            $actor=$admins->firstWhere('id',$actorId);
            abort_unless($actor && $actor->active && (int)$actor->auth_version===$actorVersion,403);
            $admin=$id?$admins->firstWhere('id',$id):new PlatformAdmin(['auth_version'=>0]);abort_if($id&&!$admin,404);
            if($id&&(int)$admin->auth_version!==(int)$data['expected_version'])throw ValidationException::withMessages(['expected_version'=>'O administrador mudou. Recarregue antes de salvar.']);
            if($id&&$admin->active&&!$data['active']&&$admins->where('active',true)->count()===1)throw ValidationException::withMessages(['active'=>'Mantenha pelo menos um administrador central ativo.']);
            $before=$admin->exists?$admin->only(['name','email','active']):[];
            $admin->fill(['name'=>$data['name'],'email'=>$data['email'],'active'=>(bool)$data['active']]);
            if(!empty($data['password']))$admin->password=$data['password'];
            $admin->auth_version=(int)$admin->auth_version+1;$admin->save();
            PlatformAudit::create(['platform_admin_id'=>$actor->id,'actor_name'=>$actor->name,'action'=>$id?'platform.admin_updated':'platform.admin_created','changes'=>['target_id'=>$admin->id,'before'=>$before,'after'=>$admin->only(['name','email','active']),'password_changed'=>!empty($data['password'])]]);
            return $admin;
        },3);
    }
}
