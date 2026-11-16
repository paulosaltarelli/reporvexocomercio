<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\Access;
use App\Platform\CompanyAdministration;
use App\Platform\PlatformAudit;
use App\Platform\ProvisionRequest;
use App\Platform\RequestCompanyProvision;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private const PUBLIC_COLUMNS = ['id', 'name', 'slug', 'active', 'is_demonstration', 'storage_mode', 'provisioned_at', 'platform_version', 'created_at', 'updated_at'];

    private function actor(Request $request)
    {
        return Access::admin((int) $request->session()->get('platform_admin_id', 0), (int) $request->session()->get('platform_auth_version', 0));
    }

    public function index(Request $request)
    {
        $this->actor($request);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive']);
        $companies = Tenant::select(self::PUBLIC_COLUMNS)->orderBy('name');
        if (filled($data['q'] ?? null)) {
            $search = trim($data['q']);
            $companies->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('slug', 'like', '%'.$search.'%'));
        }
        if (filled($data['status'] ?? null)) {
            $companies->where('active', $data['status'] === 'active');
        }
        return view('platform.index', ['companies' => $companies->paginate(20)->withQueryString(), 'filters' => $data, 'provisionAvailable' => false, 'requestAvailable' => true, 'requests' => ProvisionRequest::latest('id')->paginate(10, ['*'], 'requests_page')]);
    }

    public function create(Request $request)
    {
        $this->actor($request);
        return view('platform.form', ['provisionAvailable' => false, 'requestAvailable' => true, 'requestKey' => (string) Str::uuid()]);
    }

    public function store(Request $request, RequestCompanyProvision $service)
    {
        $actor = $this->actor($request);
        $job = $service->request($request->except('_token'), $actor->id, $actor->auth_version);
        return redirect()->route('platform.requests.show', ['id' => $job->id])->with('success', 'Solicitação registrada. A empresa ainda não foi implantada nem ativada.');
    }

    public function provisionRequest(Request $request, int $id)
    {
        $this->actor($request);
        return view('platform.request', ['provisionRequest' => ProvisionRequest::findOrFail($id)]);
    }

    public function show(Request $request, int $id)
    {
        $this->actor($request);
        return view('platform.show', ['company' => Tenant::select(self::PUBLIC_COLUMNS)->findOrFail($id), 'audits' => PlatformAudit::where('tenant_id', $id)->latest('id')->limit(30)->get(), 'provisionAvailable' => false]);
    }

    public function status(Request $request, int $id, CompanyAdministration $service)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['active' => 'required|boolean', 'expected_version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:1000']);
        $service->status($id, $data, $actor->id, $actor->auth_version);
        return redirect()->route('platform.companies.show', ['id' => $id])->with('success', 'Disponibilidade da empresa atualizada. Nenhum dado operacional foi apagado.');
    }
}
