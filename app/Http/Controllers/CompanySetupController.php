<?php
namespace App\Http\Controllers;

use App\Models\{BusinessProfile, CompanyOperation};
use App\Services\CompanyOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanySetupController extends Controller
{
    private function admin(Request $r): int
    {
        $u=$r->attributes->get('currentUser');
        abort_unless($u && $u->role==='admin',403);
        return (int)$u->id;
    }
    public function index(Request $r, CompanyOperations $operations)
    {
        $this->admin($r);
        $data=$r->validate(['step'=>'nullable|integer|between:1,5']);
        return view('setup.index',[
            'step'=>(int)($data['step']??1), 'configuration'=>$operations->current(),
            'setup'=>CompanyOperation::find(1), 'profile'=>BusinessProfile::find(1), 'pending'=>$operations->pending(),
            'audit'=>DB::connection('tenant')->table('company_operation_audits')->latest('id')->limit(10)->get(['action','version','created_at']),
        ]);
    }
    public function save(Request $r,string $company,CompanyOperations $operations)
    {
        $actor=$this->admin($r);
        $operations->save($r->all(),$actor);
        return redirect()->route('setup',['company'=>$company,'step'=>3])->with('success','Configurações salvas. As novas operações já seguem estas escolhas.');
    }
    public function complete(Request $r,string $company,CompanyOperations $operations)
    {
        $actor=$this->admin($r);
        $data=$r->validate(['version'=>'required|integer|min:0','reviewed'=>'required|accepted']);
        $operations->complete((int)$data['version'],$actor);
        return redirect()->route('setup',['company'=>$company,'step'=>5])->with('success','Implantação conferida. Isso não habilita emissão fiscal em produção.');
    }
}
