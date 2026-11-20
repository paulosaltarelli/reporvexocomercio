<?php

namespace App\Http\Controllers;

use App\Platform\Billing\CompanyPix;
use App\Platform\Contract;
use App\Platform\ContractInstallment;
use Illuminate\Http\Request;

final class LicenseSelfServiceController extends Controller
{
    public function index(Request $request)
    {
        $tenant = $request->attributes->get('tenant');
        $contracts = Contract::where('tenant_id', $tenant->id)->orderByDesc('start_date')->paginate(5, ['*'], 'contratos')->withQueryString();
        $installments = ContractInstallment::where('tenant_id', $tenant->id)
            ->orderByRaw('CASE WHEN cancelled_at IS NULL AND paid_amount < amount THEN 0 ELSE 1 END')
            ->orderBy('due_date')->orderBy('id')->paginate(20, ['*'], 'parcelas')->withQueryString();
        return view('license.index', compact('contracts', 'installments'));
    }

    public function show(Request $request, string $company, int $installment, CompanyPix $pix)
    {
        $tenant = $request->attributes->get('tenant');
        $row = ContractInstallment::with('contract')->where('tenant_id', $tenant->id)->findOrFail($installment);
        $contract = $row->contract;
        $charge = $pix->view($tenant->id, $row->id);
        return response()->view('license.show', [
            'installment' => $row, 'charge' => $charge, 'pixAvailable' => $pix->availableFor($tenant),
            'graceEnds' => $contract->inImplantationGrace() ? $contract->billingStartDate() : null,
            'restrictionStarts' => $contract->restricted_modules ? $row->due_date->addDays($contract->overdue_grace_days + 1) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request, string $company, int $installment, CompanyPix $pix)
    {
        return $this->sync($request, $installment, $pix, true);
    }

    public function refresh(Request $request, string $company, int $installment, CompanyPix $pix)
    {
        return $this->sync($request, $installment, $pix, false);
    }

    private function sync(Request $request, int $installment, CompanyPix $pix, bool $create)
    {
        $tenant = $request->attributes->get('tenant');
        $row = ContractInstallment::where('tenant_id', $tenant->id)->findOrFail($installment);
        $pix->sync($tenant->id, $row->id, $create);
        return redirect()->route('license.installments.show', ['company' => $tenant->slug, 'installment' => $row->id])
            ->with('success', $create ? 'Cobrança Pix consultada. Confira o estado da parcela.' : 'Situação do pagamento atualizada.');
    }
}
