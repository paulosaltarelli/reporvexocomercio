<?php
namespace App\Http\Controllers;

use App\Models\{BusinessProfile, CompanyOperation, Seller, User};
use App\Services\CompanyOnboarding;
use App\Access\{AccessProfiles,Permissions};
use Illuminate\Validation\ValidationException;
use App\Services\CompanyOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanySetupController extends Controller
{
    private function admin(Request $r): int
    {
        $u=$r->attributes->get('currentUser');
        abort_unless($u && ($u->role==='admin' || (\App\Services\InstallerAccess::open() && \App\Services\InstallerAccess::granted($u))),403);
        return (int)$u->id;
    }
    public function index(Request $r, CompanyOperations $operations)
    {
        $this->admin($r);
        $data=$r->validate(['step'=>'nullable|integer|between:1,5']);
        $step=(int)($data['step']??app(CompanyOnboarding::class)->step());
        if ($step===3 && !config('fiscal.product_invoices_enabled')) {
            return redirect()->route('setup',['company'=>$r->route('company'),'step'=>4]);
        }
        $installer = $r->attributes->get('currentUser')->role===User::ROLE_INSTALLER;
        $sellers = Seller::where('active',true)->orderBy('name')->get($installer?['id','name']:['*']);
        $pending = $operations->pendingSteps();
        if ($sellers->isEmpty()) $pending['Cadastre ao menos um responsável pelo caixa na etapa Equipe.'] = 4;
        return view('setup.index',[
            'step'=>$step, 'configuration'=>$operations->current(),
            'wizardMode'=>$installer || app(CompanyOnboarding::class)->required($r->attributes->get('tenant')),
            'users'=>$installer ? collect() : User::where('role','<>',User::ROLE_INSTALLER)->orderBy('name')->get()->reject(fn($u)=>\App\Platform\SupportAccess::isManaged($u)), 'sellers'=>$sellers,
            'accessState'=>$installer ? ['profiles'=>[]] : app(AccessProfiles::class)->state(),'profileService'=>app(AccessProfiles::class),
            'setup'=>CompanyOperation::find(1), 'profile'=>BusinessProfile::find(1)??new BusinessProfile(['legal_name'=>$r->attributes->get('tenant')->name]), 'pending'=>$pending,
            'audit'=>$installer ? collect() : DB::connection('tenant')->table('company_operation_audits')->whereIn('action',['configuration','company_changed','completed','setup_step','nfse_draft'])->latest('id')->limit(10)->get(['action','version','created_at']),
        ] + ($installer ? [] : app(BusinessController::class)->viewData($r)));
    }
    public function company(Request $r,string $company)
    {
        $actor=$this->admin($r);
        $r->validate(['document'=>'required|string|max:18']);
        if(!\App\Fiscal\TaxpayerDocument::cnpj((string)$r->input('document')))throw ValidationException::withMessages(['document'=>'Informe um CNPJ válido para a empresa.']);
        $response=app(BusinessController::class)->save($r);
        app(CompanyOnboarding::class)->advance(2,$actor);
        return $response;
    }
    public function fiscalContinue(Request $r,string $company,CompanyOperations $operations)
    {
        $actor=$this->admin($r);
        if($pending=$operations->pending())throw ValidationException::withMessages(['configuration'=>$pending]);
        app(CompanyOnboarding::class)->advance(4,$actor);
        return redirect()->route('setup',['company'=>$company,'step'=>4]);
    }
    public function team(Request $r,string $company)
    {
        $actor=$this->admin($r);
        if ($r->attributes->get('currentUser')->role===User::ROLE_INSTALLER) {
            $allowed = ['_token','name','phone','email','notes','active'];
            abort_if(array_diff(array_keys($r->all()),$allowed) || !$r->boolean('active'),403);
        }
        DB::connection('tenant')->transaction(function()use($r,$company,$actor){
            app(SellerController::class)->save($r,$company);
            app(CompanyOnboarding::class)->advance(5,$actor);
        },3);
        return redirect()->route('setup',['company'=>$company,'step'=>5])->with('success','Equipe e responsável pelo caixa salvos. Confira a implantação.');
    }
    public function save(Request $r,string $company,CompanyOperations $operations)
    {
        $actor=$this->admin($r);
        $operations->save($r->all(),$actor);
        $next=config('fiscal.product_invoices_enabled')?3:4;
        app(CompanyOnboarding::class)->advance($next,$actor);
        return redirect()->route('setup',['company'=>$company,'step'=>$next])->with('success','Configurações salvas. As novas operações já seguem estas escolhas.');
    }
    public function complete(Request $r,string $company,CompanyOperations $operations)
    {
        $actor=$this->admin($r);
        $data=$r->validate(['version'=>'required|integer|min:0','reviewed'=>'required|accepted']);
        if(!Seller::where('active',true)->exists())throw ValidationException::withMessages(['team'=>'Cadastre ao menos um responsável pelo caixa na etapa Equipe.']);
        $operations->complete((int)$data['version'],$actor);
        if ($r->attributes->get('currentUser')->role===User::ROLE_INSTALLER) {
            $r->session()->invalidate();
            $r->session()->regenerateToken();
            return redirect()->route('login',['company'=>$company])->with('success','Implantação concluída. Seu acesso temporário à empresa foi encerrado.');
        }
        return redirect()->route('dashboard',['company'=>$company])->with('success','Implantação concluída. O sistema está configurado para iniciar seus cadastros e operar.');
    }
}
