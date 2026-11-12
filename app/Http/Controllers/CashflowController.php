<?php

namespace App\Http\Controllers;

use App\Models\FinancialEntry;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class CashflowController extends Controller
{
    public function index(Request $r)
    {
        abort_unless($r->attributes->get('currentUser')->role === 'admin', 403);
        $d = $r->validate(['days' => 'nullable|integer|in:7,15,30,60,90', 'balance' => 'nullable|numeric|min:-999999999.99|max:999999999999.99|decimal:0,2']);
        $days = (int) ($d['days'] ?? 30);
        $today = CarbonImmutable::today();
        $end = $today->addDays($days - 1);
        $incoming = Payment::where('direction', 'in')->sum('amount');
        $outgoing = Payment::where('direction', 'out')->sum('amount');
        $registered = bcsub((string) $incoming, (string) $outgoing, 2);
        $initial = (string) ($d['balance'] ?? $registered);
        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $today->addDays($i)->toDateString();
            $rows[$date] = ['date' => $date, 'in' => '0.00', 'out' => '0.00', 'overdue_in' => '0.00', 'overdue_out' => '0.00'];
        }
        $pending = FinancialEntry::whereNull('cancelled_at')->whereColumn('paid_amount', '<', 'amount')->where('due_date', '<=', $end->toDateString())->orderBy('due_date')->get();
        $overdueIn = '0.00';
        $overdueOut = '0.00';
        foreach ($pending as $entry) {
            $due = $entry->due_date->toDateString();
            $overdue = $due < $today->toDateString();
            $bucket = $overdue ? $today->toDateString() : $due;
            $amount = bcsub($entry->amount, $entry->paid_amount, 2);
            $direction = $entry->direction;
            $rows[$bucket][$direction] = bcadd($rows[$bucket][$direction], $amount, 2);
            if ($overdue) {
                $rows[$bucket]['overdue_'.$direction] = bcadd($rows[$bucket]['overdue_'.$direction], $amount, 2);
                if ($direction === 'in') {
                    $overdueIn = bcadd($overdueIn, $amount, 2);
                } else {
                    $overdueOut = bcadd($overdueOut, $amount, 2);
                }
            }
        }
        $balance = $initial;
        $forecastIn = '0.00';
        $forecastOut = '0.00';
        foreach ($rows as &$row) {
            $forecastIn = bcadd($forecastIn, $row['in'], 2);
            $forecastOut = bcadd($forecastOut, $row['out'], 2);
            $balance = bcsub(bcadd($balance, $row['in'], 2), $row['out'], 2);
            $row['balance'] = $balance;
        }unset($row);
        $history = Payment::whereBetween('paid_on', [$today->subDays(13)->toDateString(), $today->toDateString()])->selectRaw("paid_on, SUM(CASE WHEN direction='in' THEN amount ELSE 0 END) as incoming, SUM(CASE WHEN direction='out' THEN amount ELSE 0 END) as outgoing")->groupBy('paid_on')->orderBy('paid_on')->get();

        return view('cashflow', ['days' => $days, 'initial' => $initial, 'registered' => $registered, 'rows' => $rows, 'history' => $history, 'forecastIn' => $forecastIn, 'forecastOut' => $forecastOut, 'endBalance' => $balance, 'overdueIn' => $overdueIn, 'overdueOut' => $overdueOut]);
    }
}
