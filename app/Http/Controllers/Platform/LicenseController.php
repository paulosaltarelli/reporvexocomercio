<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\Contract;
use App\Platform\ContractAccess;
use App\Platform\ContractBilling;
use App\Platform\ContractInstallment;
use Illuminate\Http\Request;

final class LicenseController extends Controller
{
    public function index(int $id)
    {
        $company = Tenant::select('id', 'name', 'slug')->findOrFail($id);
        $contracts = Contract::where('tenant_id', $id)->orderByDesc('start_date')->paginate(10, ['*'], 'contratos')->withQueryString();
        $installments = ContractInstallment::where('tenant_id', $id)->orderByDesc('due_date')->orderByDesc('id')->paginate(20, ['*'], 'parcelas')->withQueryString();
        return view('platform.license.index', compact('company', 'contracts', 'installments'));
    }

    public function create(int $id)
    {
        return $this->form($id, new Contract());
    }

    public function edit(int $id, int $contract)
    {
        return $this->form($id, Contract::where('tenant_id', $id)->findOrFail($contract));
    }

    private function form(int $id, Contract $contract)
    {
        return view('platform.license.form', [
            'company' => Tenant::select('id', 'name')->findOrFail($id),
            'contract' => $contract, 'modules' => ContractAccess::MODULES,
        ]);
    }

    public function store(Request $request, int $id, ContractBilling $billing)
    {
        $actor = $request->attributes->get('platformAdmin');
        $billing->create($id, $this->fields($request), $actor->id, $actor->auth_version);
        return redirect()->route('platform.license.index', ['id' => $id])->with('success', 'Contrato criado. Confira as parcelas da licença.');
    }

    public function update(Request $request, int $id, int $contract, ContractBilling $billing)
    {
        $actor = $request->attributes->get('platformAdmin');
        $billing->update($id, $contract, $this->fields($request), $actor->id, $actor->auth_version);
        return redirect()->route('platform.license.index', ['id' => $id])->with('success', 'Contrato atualizado. Confira o calendário das parcelas.');
    }

    private function fields(Request $request): array
    {
        return $request->only(['start_date', 'end_date', 'monthly_amount', 'due_day', 'implantation_grace_days', 'overdue_grace_days', 'expected_version']) + [
            'restricted_modules' => $request->input('restricted_modules', []),
        ];
    }
}
