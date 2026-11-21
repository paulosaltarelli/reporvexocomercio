<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Platform\Billing\CompanyPix;
use App\Platform\Contract;
use App\Platform\PlatformAudit;
use App\Platform\ContractAccess;
use App\Platform\ContractBilling;
use App\Platform\ContractInstallment;
use Illuminate\Http\Request;

final class LicenseController extends Controller
{
    public function index(Request $request, int $id)
    {
        $filters = $request->validate(['parcelas_status'=>'nullable|in:all,open,history']);
        $installmentFilter = $filters['parcelas_status'] ?? 'all';
        $company = Tenant::select('id', 'name', 'slug')->findOrFail($id);
        $contracts = Contract::where('tenant_id', $id)->orderByDesc('start_date')->paginate(10, ['*'], 'contratos')
            ->appends($request->only(['parcelas','parcelas_status']));
        $installmentQuery = ContractInstallment::where('tenant_id', $id);
        if ($installmentFilter === 'open') {
            $installmentQuery->whereNull('cancelled_at')->whereColumn('paid_amount','<','amount');
        } elseif ($installmentFilter === 'history') {
            $installmentQuery->where(fn($query)=>$query->whereNotNull('cancelled_at')->orWhereColumn('paid_amount','>=','amount'));
        }
        $installments = $installmentQuery
            ->orderByRaw('CASE WHEN cancelled_at IS NOT NULL OR paid_amount >= amount THEN 1 ELSE 0 END')
            ->orderBy('due_date')->orderBy('id')->paginate(20, ['*'], 'parcelas')
            ->appends($request->only(['contratos','parcelas_status']));
        return view('platform.license.index', compact('company', 'contracts', 'installments', 'installmentFilter'));
    }

    public function installment(int $id, int $installment, CompanyPix $pix)
    {
        $company = Tenant::findOrFail($id);
        $row = ContractInstallment::with('contract')->where('tenant_id', $id)->findOrFail($installment);
        $charge = $pix->view($id, $row->id);
        $canIssue = $pix->availableForCentral($company)
            && !in_array($row->status, ['paid', 'cancelled'], true)
            && !$row->contract->inImplantationGrace();

        return response()->view('platform.license.installment', [
            'company' => $company, 'installment' => $row, 'charge' => $charge, 'canIssue' => $canIssue,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function issueInstallment(Request $request, int $id, int $installment, CompanyPix $pix)
    {
        ContractInstallment::where('tenant_id', $id)->findOrFail($installment);
        $charge = $pix->issueFromCentral($id, $installment);
        $actor = $request->attributes->get('platformAdmin');
        PlatformAudit::create([
            'platform_admin_id' => $actor->id,
            'actor_name' => $actor->name,
            'tenant_id' => $id,
            'action' => 'platform.license_pix_requested',
            'changes' => ['tenant_id' => $id, 'installment_id' => $installment,
                'txid' => $charge['txid'], 'environment' => $charge['environment']],
        ]);

        return redirect()->route('platform.license.installments.show', [$id, $installment]);
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
