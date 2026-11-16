<?php
namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\{AdminManagement,PlatformAdmin,PlatformAudit};
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $r)
    {
        return view('platform.admins',['admins'=>PlatformAdmin::orderBy('name')->get(),'audits'=>PlatformAudit::where('action','like','platform.admin_%')->latest('id')->limit(20)->get()]);
    }
    public function save(Request $r,AdminManagement $service,?int $id=null)
    {
        $actor=$r->attributes->get('platformAdmin');
        $saved=$service->save($r->except('_token'),$actor->id,$actor->auth_version,$id);
        if($saved->id===$actor->id){$r->session()->regenerate();$r->session()->put('platform_auth_version',$saved->auth_version);}
        return redirect()->route('platform.admins')->with('success','Administrador salvo. Sessões anteriores, inclusive de suporte nas lojas, foram revogadas.');
    }
}
