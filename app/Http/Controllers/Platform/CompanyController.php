<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\Access;
use App\Platform\CompanyAdministration;
use App\Platform\PlatformAudit;
use App\Platform\ProvisionRequest;
use App\Platform\RequestCompanyProvision;
use App\Services\OperationalModes;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CompanyController extends Controller
{
    private const PUBLIC_COLUMNS = ['id', 'name', 'slug', 'active', 'is_demonstration', 'storage_mode', 'provisioned_at', 'platform_version', 'operation_segment', 'created_at', 'updated_at'];

    private function actor(Request $request)
    {
        return Access::admin((int) $request->session()->get('platform_admin_id', 0), (int) $request->session()->get('platform_auth_version', 0));
    }

    public function index(Request $request)
    {
        $this->actor($request);
        $segments = OperationalModes::SEGMENTS + ['unassigned' => 'Segmento a definir'];
        $data = $request->validate([
            'q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive',
            'segment' => ['nullable', Rule::in(array_keys($segments))],
        ]);
        $companies = Tenant::select(self::PUBLIC_COLUMNS);
        if (filled($data['q'] ?? null)) {
            $search = '%'.strtr(trim($data['q']), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $companies->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$search])->orWhereRaw("slug LIKE ? ESCAPE '!'", [$search]));
        }
        if (filled($data['status'] ?? null)) {
            $companies->where('active', $data['status'] === 'active');
        }
        $requests = ProvisionRequest::leftJoin('tenants', 'tenants.id', '=', 'platform_provision_requests.tenant_id')
            ->select('platform_provision_requests.id', 'platform_provision_requests.name', 'platform_provision_requests.slug',
                'platform_provision_requests.is_demonstration', 'platform_provision_requests.status',
                'platform_provision_requests.admin_name', 'platform_provision_requests.created_at', 'tenants.operation_segment');
        if ($segment = $data['segment'] ?? null) {
            foreach ([$companies, $requests] as $query) {
                if ($segment === 'unassigned') {
                    $query->whereNull('tenants.operation_segment');
                } else {
                    $query->where('tenants.operation_segment', $segment);
                }
            }
        }
        $segmentCounts = (clone $companies)->select('operation_segment')->selectRaw('COUNT(*) AS quantity')
            ->groupBy('operation_segment')->pluck('quantity', 'operation_segment');
        $companies->orderByRaw('operation_segment IS NULL')->orderBy('operation_segment')->orderBy('name')->orderBy('id');
        return view('platform.index', [
            'companies' => $companies->paginate(20)->appends($data + $request->only('requests_page')),
            'filters' => $data, 'segments' => $segments, 'segmentCounts' => $segmentCounts,
            'provisionAvailable' => false, 'requestAvailable' => true,
            'requests' => $requests->latest('platform_provision_requests.id')->paginate(10, ['*'], 'requests_page'),
        ]);
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
        $job = ProvisionRequest::findOrFail($id);
        $companyActive = $job->tenant_id && Tenant::whereKey($job->tenant_id)->where('active', true)->exists();
        return view('platform.request', ['provisionRequest'=>$job, 'companyActive'=>$companyActive]);
    }

    public function resendActivation(Request $request, int $id)
    {
        $this->actor($request);
        $updated = ProvisionRequest::whereKey($id)->where('status', 'completed')
            ->where('activation_status', 'sent')->whereIn('tenant_id', Tenant::where('active', true)->select('id'))
            ->update(['activation_status' => 'queued', 'activation_digest' => null, 'activation_expires_at' => null, 'activation_retry_at' => null]);
        abort_unless($updated, 409, 'O estado do convite mudou. Atualize a solicitação.');
        return redirect()->route('platform.requests.show', ['id' => $id])->with('success', 'Novo convite solicitado para o administrador inicial.');
    }

    public function show(Request $request, int $id)
    {
        $this->actor($request);
        return view('platform.show', ['company' => Tenant::select(self::PUBLIC_COLUMNS)->findOrFail($id), 'audits' => PlatformAudit::where('tenant_id', $id)->latest('id')->paginate(30, ['id','created_at','action','actor_name','reason']), 'provisionAvailable' => false]);
    }

    public function status(Request $request, int $id, CompanyAdministration $service)
    {
        $actor = $this->actor($request);
        $data = $request->validate(['active' => 'required|boolean', 'expected_version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:1000']);
        $service->status($id, $data, $actor->id, $actor->auth_version);
        return redirect()->route('platform.companies.show', ['id' => $id])->with('success', 'Disponibilidade da empresa atualizada. Nenhum dado operacional foi apagado.');
    }
}
