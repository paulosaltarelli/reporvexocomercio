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
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
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

        return view('cash', ['date' => $date, 'summary' => $summary, 'closure' => CashClosure::where('date', $date)->first(), 'history' => CashClosure::orderByDesc('date')->paginate(15)->withQueryString(), 'nextDate' => $next, 'last' => $last]);
    }

    public function close(Request $r, string $company)
    {
        $this->admin($r);
        $d = $r->validate(['request_key' => 'required|uuid', 'date' => 'required|date_format:Y-m-d|before_or_equal:today', 'opening_balance' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'counted_balance' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2', 'notes' => 'nullable|string|max:1000']);
        app(CashService::class)->close($d, $r->attributes->get('currentUser')->id);

        return redirect()->route('cash', ['company' => $company, 'date' => $d['date']])->with('success','Caixa fechado. O histórico foi preservado e novas baixas nesse dia estão bloqueadas.');
    }
}
