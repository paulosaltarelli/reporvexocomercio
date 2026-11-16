<?php
namespace App\Http\Controllers;

use App\Access\{AccessProfiles,Permissions};
use App\Models\User;
use Illuminate\Http\Request;

class AccessProfileController extends Controller
{
    public function index(Request $request,AccessProfiles $profiles)
    {
        abort_unless($request->attributes->get('currentUser')->role==='admin',403);
        $state=$profiles->state(true);$selected=$request->query('profile');
        abort_unless($selected===null||is_string($selected)&&isset($state['profiles'][$selected]),404);
        $users=User::orderBy('name')->get();
        return view('access-profiles',['accessState'=>$state,'permissionGroups'=>Permissions::GROUPS,'dependencies'=>Permissions::DEPENDENCIES,'selectedId'=>$selected,'users'=>$users,'profileService'=>$profiles]);
    }
    public function save(Request $request,string $company,AccessProfiles $profiles)
    {
        $id=$request->input('profile_id');abort_unless($id===null||is_string($id),422);
        $input=$request->except('_token');$input['permissions']=$request->input('permissions',[]);
        $id=$profiles->save($input,(int)$request->attributes->get('currentUser')->id,$id);
        return redirect()->route('access.profiles',['company'=>$company,'profile'=>$id])->with('success','Perfil salvo. As liberações passam a valer na próxima requisição dos usuários vinculados.');
    }
    public function delete(Request $request,string $company,AccessProfiles $profiles)
    {
        $data=$request->validate(['profile_id'=>'required|string|max:60','version'=>'required|integer|min:0']);
        $profiles->delete($data['profile_id'],(int)$data['version'],(int)$request->attributes->get('currentUser')->id);
        return redirect()->route('access.profiles',['company'=>$company])->with('success','Perfil excluído.');
    }
}
