<?php

namespace App\Http\Controllers;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SellerController extends Controller
{
    public function index(Request $request)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        $data=$request->validate(['edit'=>'nullable|integer|exists:tenant.sellers,id']);
        $editing=isset($data['edit'])?Seller::findOrFail($data['edit']):null;
        $users=User::orderBy('name')->get()->filter(function(User $user)use($editing){
            if($user->getRawOriginal('role')!==User::ROLE_INSTALLER || (int)$editing?->user_id===$user->id)return true;
            return \App\Services\InstallerAccess::applyGrant($user) && $user->role==='admin';
        });
        return view('sellers',['rows'=>Seller::with('user')->orderBy('name')->paginate(20)->withQueryString(),
            'editing'=>$editing,'users'=>$users]);
    }

    public function save(Request $request, string $company)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        $id=$request->validate(['id'=>'nullable|integer|exists:tenant.sellers,id'])['id']??null;
        $data=$request->validate(['name'=>'required|string|max:180',
            'user_id'=>['nullable','integer','exists:tenant.users,id',Rule::unique('tenant.sellers','user_id')->ignore($id)],
            'active'=>'required|boolean','phone'=>'nullable|string|max:30','email'=>'nullable|email|max:180','notes'=>'nullable|string|max:1000']);
        $data['name']=trim($data['name']);
        if($data['name']==='') throw ValidationException::withMessages(['name'=>'Informe o nome do vendedor.']);
        try {
            DB::connection('tenant')->transaction(function()use($id,$data){
                $seller=$id?Seller::lockForUpdate()->findOrFail($id):new Seller;
                if(!empty($data['user_id'])){
                    $linked=User::whereKey($data['user_id'])->sharedLock()->firstOrFail();
                    $existingLink=$seller->exists && (int)$seller->user_id===$linked->id;
                    if($linked->getRawOriginal('role')===User::ROLE_INSTALLER && !$existingLink){
                        abort_unless(\App\Services\InstallerAccess::applyGrant($linked) && $linked->role==='admin',403);
                    }
                }
                $seller->fill($data)->save();
            },3);
        } catch(UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['user_id'=>'Este usuário já está vinculado a outro vendedor. Atualize a página e escolha outro vínculo.']);
        }
        return redirect()->route('sellers',['company'=>$company])->with('success','Vendedor salvo. As atribuições históricas foram preservadas.');
    }
}
