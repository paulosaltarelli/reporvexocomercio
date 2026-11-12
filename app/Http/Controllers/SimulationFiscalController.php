<?php

namespace App\Http\Controllers;

use App\Fiscal\SimulationFiscal;
use App\Models\FiscalSimulation;
use Illuminate\Http\Request;

class SimulationFiscalController extends Controller
{
    private function context(Request $r, SimulationFiscal $service): array
    {
        $tenant = $r->attributes->get('tenant');
        $userId = (int) $r->attributes->get('currentUser')->id;
        $service->authorize($tenant, $userId);
        return [$tenant, $userId];
    }

    private function onlyInternal(Request $r): void
    {
        $r->validate([
            'tenant_id' => 'prohibited', 'snapshot' => 'prohibited', 'state' => 'prohibited',
            'access_key' => 'prohibited', 'protocol' => 'prohibited', 'number' => 'prohibited',
            'series' => 'prohibited', 'certificate' => 'prohibited', 'environment' => 'prohibited',
        ]);
    }

    public function index(Request $r, string $company, SimulationFiscal $service)
    {
        [$tenant] = $this->context($r, $service);
        return view('fiscal.simulations.index', ['simulations' => FiscalSimulation::where('tenant_id', $tenant->id)->with('sale', 'requester')->latest('id')->paginate(20)]);
    }

    public function create(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        $this->onlyInternal($r);
        $data = $r->validate(['request_key' => 'required|uuid', 'scenario' => 'required|in:accepted,rejected,uncertain']);
        $simulation = $service->create($tenant, $id, $userId, $data['request_key'], $data['scenario']);
        return redirect()->route('fiscal.simulations.show', ['company' => $company, 'id' => $simulation->id])->with('success', 'Simulação interna registrada, sem valor fiscal. Nenhuma nota foi enviada.');
    }

    public function show(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        return view('fiscal.simulations.show', ['simulation' => $service->find($tenant, $id, $userId)]);
    }

    public function consult(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        $this->onlyInternal($r);
        $data = $r->validate(['request_key' => 'required|uuid']);
        $service->consult($tenant, $id, $userId, $data['request_key']);
        return back()->with('success', 'Consulta simulada concluída localmente. Nenhuma SEFAZ foi consultada.');
    }

    public function cancel(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        $this->onlyInternal($r);
        $data = $r->validate(['request_key' => 'required|uuid', 'reason' => 'required|string|min:15|max:255']);
        $service->cancel($tenant, $id, $userId, $data['reason'], $data['request_key']);
        return back()->with('success', 'Simulação cancelada localmente. Venda, estoque e financeiro preservados.');
    }

    public function download(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        $data = $service->export($tenant, $id, $userId);
        return response()->json($data, 200, [
            'Content-Disposition' => 'attachment; filename="simulacao-'.$data['reference'].'.json"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function receipt(Request $r, string $company, int $id, SimulationFiscal $service)
    {
        [$tenant, $userId] = $this->context($r, $service);
        return response()->view('fiscal.simulations.receipt', ['simulation' => $service->find($tenant, $id, $userId)])->withHeaders(['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
