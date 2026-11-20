<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\InstallerInvitation;
use App\Platform\InstallerInvitations;
use Illuminate\Http\Request;

final class InstallerAccessController extends Controller
{
    public function index(int $id, InstallerInvitations $access)
    {
        $company=Tenant::findOrFail($id);
        return view('platform.accesses.index',[
            'company'=>$company, 'open'=>$access->setupOpen($company),
            'invitations'=>InstallerInvitation::where('tenant_id',$id)->latest('id')->paginate(25),
        ]);
    }

    public function create(Request $request,int $id,InstallerInvitations $access)
    {
        return $this->result($access->change($request,$id,null,'create'));
    }

    public function resend(Request $request,int $id,int $invitation,InstallerInvitations $access)
    {
        return $this->result($access->change($request,$id,$invitation,'resend'));
    }

    public function revoke(Request $request,int $id,int $invitation,InstallerInvitations $access)
    {
        return $this->result($access->change($request,$id,$invitation,'revoke'));
    }

    private function result(InstallerInvitation $invitation)
    {
        $redirect=redirect()->route('platform.company-access.index',['id'=>$invitation->tenant_id]);
        if ($invitation->status==='revoked') return $redirect->with('success','Acesso revogado.');
        if ($invitation->delivery_status==='failed') return $redirect->withErrors(['delivery'=>'O convite não foi enviado. Confira o serviço SMTP da Central e reenvie pela lista de acessos.']);
        return $redirect->with('success','Convite enviado para '.$invitation->email.'. O acesso ficará disponível após o aceite.');
    }
}
