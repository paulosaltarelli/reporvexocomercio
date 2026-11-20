<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReceivablesController extends Controller
{
    public function __invoke(Request $request)
    {
        Access::admin((int) $request->session()->get('platform_admin_id'), (int) $request->session()->get('platform_auth_version'));
        $labels = [
            'pending' => 'Em aberto',
            'partial' => 'Parcialmente paga',
            'overdue' => 'Em atraso',
            'paid' => 'Paga',
            'cancelled' => 'Cancelada',
        ];
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'month' => ['nullable', 'date_format:Y-m'],
            'status' => ['nullable', Rule::in(array_keys($labels))],
        ]);
        $central = DB::connection('central');
        $today = now()->toDateString();
        $rows = $central->table('platform_contract_installments as i')
            ->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->select('i.id', 'i.contract_id', 'i.tenant_id', 'i.due_date', 'i.amount', 'i.paid_amount', 't.name as company_name')
            ->selectRaw("CASE
                WHEN i.cancelled_at IS NOT NULL THEN 'cancelled'
                WHEN i.paid_amount >= i.amount THEN 'paid'
                WHEN i.due_date < ? THEN 'overdue'
                WHEN i.paid_amount > 0 THEN 'partial'
                ELSE 'pending' END AS payment_status", [$today]);
        if (filled($filters['q'] ?? null)) {
            $pattern = '%'.trim($filters['q']).'%';
            $rows->where(fn ($query) => $query->where('t.name', 'like', $pattern)->orWhere('t.slug', 'like', $pattern));
        }
        if (filled($filters['month'] ?? null)) {
            $month = Carbon::createFromFormat('!Y-m', $filters['month']);
            $rows->whereBetween('i.due_date', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
        }
        $query = $central->query()->fromSub($rows, 'installments');
        if (filled($filters['status'] ?? null)) {
            $query->where('payment_status', $filters['status']);
        }
        $totals = (array) (clone $query)->selectRaw("SUM(CASE WHEN payment_status IN ('pending','partial') THEN amount - paid_amount ELSE 0 END) AS upcoming,
            SUM(CASE WHEN payment_status = 'overdue' THEN amount - paid_amount ELSE 0 END) AS overdue,
            SUM(paid_amount) AS paid")->first();
        $installments = $query->orderBy('due_date')->orderBy('id')->paginate(30)->withQueryString();

        return view('platform.receivables', compact('filters', 'totals', 'installments', 'labels'));
    }
}
