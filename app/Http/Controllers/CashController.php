<?php

namespace App\Http\Controllers;

use App\Models\CashClosure;
use App\Models\Payment;
use App\Services\CashService;
use Illuminate\Http\Request;

class CashController extends Controller
{
    private function admin(Request $r): void
    {
        app(\App\Access\AccessProfiles::class)->authorize($r);
    }

    public function index(Request $r)
    {
        $this->admin($r);
        $d = $r->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today', 'opening_balance' => 'nullable|numeric|min:0|max:999999999999.99|decimal:0,2']);
        $date = $d['date'] ?? now()->toDateString();
        $last = CashClosure::where('date', '<', $date)->orderByDesc('date')->first();
        $opening = (string) ($d['opening_balance'] ?? $last?->counted_balance ?? '0.00');
        $summary = app(CashService::class)->summary($date, $opening);
        $latest = CashClosure::orderByDesc('date')->first();
        $next = Payment::when($latest, fn ($q) => $q->where('paid_on', '>', $latest->date->toDateString()))->where('paid_on', '<=', now()->toDateString())->min('paid_on');

        return view('cash', ['date' => $date, 'summary' => $summary, 'closure' => CashClosure::where('date', $date)->first(), 'history' => CashClosure::orderByDesc('date')->paginate(15)->withQueryString(), 'nextDate' => $next, 'last' => $last,'openingRecord'=>app(CashService::class)->opening($date),'cashUsers'=>\App\Models\User::whereIn('id',array_keys($summary['by_user']??[]))->pluck('name','id')]);
    }

    public function close(Request $r, string $company)
    {
        $this->admin($r);
        if(is_array($r->input('counted_methods')))$r->merge(['counted_methods'=>array_filter($r->input('counted_methods'),fn($v)=>$v!==null&&$v!=='')]);
        $d = $r->validate(['request_key' => 'required|uuid', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'opening_balance' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'counted_balance' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'notes' => 'nullable|string|max:1000','counted_methods'=>'sometimes|array:pix,credit,debit,transfer,invoice','counted_methods.*'=>'required|numeric|min:-999999999999.99|max:999999999999.99|decimal:0,2']);
        app(CashService::class)->close($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('cash', ['company' => $company, 'date' => $d['date']])->with('success','Caixa fechado. O histórico foi preservado e novas baixas nesse dia estão bloqueadas.');
    }
    public function open(Request $r,string $company)
    {
        $this->admin($r);
        $data=$r->validate(['date'=>'required|date_format:Y-m-d','opening_balance'=>'required|numeric|min:0|max:999999999999.99|decimal:0,2']);
        app(CashService::class)->open($data,$r->attributes->get('currentUser')->id);
        return redirect()->route('cash',['company'=>$company])->with('success','Abertura de caixa registrada.');
    }
    public function movement(Request $r,string $company)
    {
        $this->admin($r);
        $data=$r->validate(['request_key'=>'required|uuid','type'=>'required|in:supply,withdrawal','description'=>'required|string|min:3|max:150','amount'=>'required|numeric|min:0.01|max:999999999999.99|decimal:0,2']);
        app(CashService::class)->movement($data,$r->attributes->get('currentUser')->id);
        return redirect()->route('cash',['company'=>$company])->with('success','Movimento em dinheiro registrado no caixa e financeiro.');
    }

}
