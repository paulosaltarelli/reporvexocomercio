<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Models\Seller;
use App\Services\Decimal;
use App\Services\CommissionSettlements;
use App\Models\FinancialEntry;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CommissionController extends Controller
{
    public function index(Request $request)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        return $this->report($request);
    }
    public function mine(Request $request)
    {
        app(\App\Access\AccessProfiles::class)->authorize($request);
        return $this->report($request,app(CommissionSettlements::class)->own($request->attributes->get('currentUser')));
    }
    private function report(Request $request,?Seller $own=null)
    {
        if($own&&($request->query->has('seller_id')||$request->query->has('salesperson_id')))abort(403,'Esta consulta mostra somente suas comissões.');
        $data = Validator::make([
            'from' => $request->query('from', now()->startOfMonth()->toDateString()),
            'to' => $request->query('to', now()->toDateString()),
            'seller_id' => $request->query('seller_id'),
            'salesperson_id' => $request->query('salesperson_id'),
        ], [
            'from'=>'required|date_format:Y-m-d', 'to'=>'required|date_format:Y-m-d|after_or_equal:from',
            'seller_id'=>'nullable|integer|exists:tenant.users,id','salesperson_id'=>'nullable|integer|exists:tenant.sellers,id',
        ])->validate();
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to'=>'Escolha um período de até 366 dias.']);
        }
        $sellerId = $own?->id ?? (isset($data['salesperson_id']) ? (int)$data['salesperson_id'] : null);
        if(isset($data['seller_id'])) {
            $legacy=Seller::where('user_id',(int)$data['seller_id'])->first();
            if(!$legacy || ($sellerId!==null && $sellerId!==(int)$legacy->id)) throw ValidationException::withMessages(['salesperson_id'=>'O filtro de vendedor é inválido ou conflita com o usuário informado.']);
            $sellerId=(int)$legacy->id;
        }
        $query = Sale::where('status', 'completed')->where('commission_total', '>', '0')
            ->whereBetween('created_at', [$data['from'].' 00:00:00', $data['to'].' 23:59:59'])
            ->when($sellerId, fn ($q) => $q->where('salesperson_id', $sellerId))
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

        $closures=app(CommissionSettlements::class)->records($sellerId)->latest('id')->paginate(10,['*'],'closures_page')->withQueryString();
        $accounts=FinancialEntry::whereIn('id',$closures->map(fn($c)=>$c->after['entry_id']))->get()->keyBy('id');
        return view('commissions', ['rows'=>$rows, 'totals'=>$totals, 'from'=>$data['from'],
            'to'=>$data['to'], 'sellerId'=>$sellerId, 'sellers'=>$own?collect([$own]):Seller::orderBy('name')->get(),'personal'=>(bool)$own,'closures'=>$closures,'closureAccounts'=>$accounts,'settlementTotals'=>app(CommissionSettlements::class)->balances($sellerId)]);
    }

    public function preview(Request $r,CommissionSettlements $service)
    {
        $service->authorize((int)$r->attributes->get('currentUser')->id,'commissions.close');
        return view('commissions.preview',['snapshot'=>$service->preview($r->query()),'requestKey'=>(string)Str::uuid()]);
    }
    public function close(Request $r,string $company,CommissionSettlements $service)
    {
        $record=$service->close($r->except('_token'),(int)$r->attributes->get('currentUser')->id);
        return redirect()->route('commission.closure',['company'=>$company,'id'=>$record->id])->with('success','Comissões fechadas e conta a pagar criada. O pagamento será registrado separadamente.');
    }
    public function mineShow(Request $r,string $company,int $id,CommissionSettlements $service)
    {return $this->show($r,$company,$id,$service);}
    public function show(Request $r,string $company,int $id,CommissionSettlements $service)
    {
        $record=$service->record($id);$personal=$r->routeIs('commissions.mine.show');
        if($personal)abort_unless((int)$record->after['salesperson_id']===$service->own($r->attributes->get('currentUser'))->id,403);
        $entry=FinancialEntry::with('payments.user')->findOrFail($record->after['entry_id']);
        return view('commissions.closure',['closure'=>$record,'account'=>$entry,'personal'=>$personal,'requestKey'=>(string)Str::uuid()]);
    }
    public function pay(Request $r,string $company,int $id,CommissionSettlements $service)
    {
        $service->pay($id,$r->except('_token'),(int)$r->attributes->get('currentUser')->id);
        return redirect()->route('commission.closure',['company'=>$company,'id'=>$id])->with('success','Pagamento da comissão registrado no financeiro e no caixa.');
    }

    /** Cumulative reconciliation avoids rounding once for each partial receipt. */
    private function eligible(Sale $sale): string
    {
        if ($sale->commission_basis === 'accrual') return (string)$sale->commission_total;
        if (bccomp($sale->total, '0', 2) <= 0) return '0.00';
        $received = (string) ($sale->settled_amount ?? '0.00');
        if (bccomp($received, '0', 2) < 0) $received = '0.00';
        if (bccomp($received, $sale->total, 2) > 0) $received = (string) $sale->total;

        return Decimal::round(bcdiv(bcmul($sale->commission_total, $received, 8), $sale->total, 8));
    }
}
