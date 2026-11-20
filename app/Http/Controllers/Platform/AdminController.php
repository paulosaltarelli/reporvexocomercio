<?php
namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\{AdminManagement,PlatformAdmin,PlatformAudit};
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function index(Request $r)
    {
        return view('platform.admins',['admins'=>PlatformAdmin::orderBy('name')->orderBy('id')->paginate(25, ['id', 'name', 'email', 'active', 'auth_version']),'audits'=>PlatformAudit::whereIn('action',['platform.admin_created','platform.admin_updated'])->latest('id')->limit(20)->get()]);
    }
    public function save(Request $r,AdminManagement $service,?int $id=null)
    {
        $actor=$r->attributes->get('platformAdmin');
        $r->merge(['admin_form' => $id === null ? 'new' : (string)$id]);
        $saved=$service->save($r->except('_token'),$actor->id,$actor->auth_version,$id);
        if($saved->id===$actor->id){$r->session()->regenerate();$r->session()->put('platform_auth_version',$saved->auth_version);}
        return redirect()->route('platform.admins')->with('success', $id === null ? 'Administrador criado.' : 'Administrador salvo. Sessões anteriores, inclusive de suporte nas lojas, foram revogadas.');
    }
}
