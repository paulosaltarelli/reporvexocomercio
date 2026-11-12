<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Services\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->attributes->get('currentUser')->role === 'admin', 403);
        $data = Validator::make([
            'from' => $request->query('from', now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', now()->toDateString()),
            'seller_id' => $request->query('seller_id'),
        ], [
            'from'=>'required|date_format:Y-m-d', 'to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'seller_id'=>'nullable|integer|exists:tenant.users,id',
        ])->validate();
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to'=>'Escolha um período de até 366 dias.']);
        }
        $sellerId = isset($data['seller_id']) ? (int) $data['seller_id'] : null;
        $query = Sale::where('status', 'completed')->where('commission_total', '>', '0')
            ->whereBetween('created_at', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])
            ->when($sellerId, fn ($q) => $q->where('seller_id', $sellerId))
            ->withSum(['entries as settled_amount'=>fn ($q) => $q->whereNull('cancelled_at')], 'paid_amount');
        $totals = ['net_sales'=>'0.00','discounts'=>'0.00','commission'=>'0.00','eligible'=>'0.00','awaiting'=>'0.00'];
        foreach ((clone $query)->orderBy('id')->cursor() as $sale) {
            $totals['net_sales'] = bcadd($totals['net_sales'], $sale->total, 2);
            $totals['discounts'] = bcadd($totals['discounts'], $sale->discount_total, 2);
            $totals['commission'] = bcadd($totals['commission'], $sale->commission_total, 2);
            $totals['eligible'] = bcadd($totals['eligible'], $this->eligible($sale), 2);
        }
        $totals['awaiting'] = bcsub($totals['commission'], $totals['eligible'], 2);
        $rows = (clone $query)->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();
        foreach ($rows as $sale) {
            $sale->eligible_commission = $this->eligible($sale);
            $sale->settled_amount = $sale->settled_amount ?? '0.00';
        }

        return view('commissions', ['rows'=>$rows, 'totals'=>$totals, 'from'=>$data['from'],
            'to'=>$data['to'], 'sellerId'=>$sellerId, 'sellers'=>User::orderBy('name')->get()]);
    }

    /** Cumulative reconciliation avoids rounding once for each partial receipt. */
    private function eligible(Sale $sale): string
    {
        if (bccomp($sale->total, '0', 2) <= 0) return '0.00';
        $received = (string) ($sale->settled_amount ?? '0.00');
        if (bccomp($received, '0', 2) < 0) $received = '0.00';
        if (bccomp($received, $sale->total, 2) > 0) $received = (string) $sale->total;

        return Decimal::round(bcdiv(bcmul($sale->commission_total, $received, 8), $sale->total, 8));
    }
}
