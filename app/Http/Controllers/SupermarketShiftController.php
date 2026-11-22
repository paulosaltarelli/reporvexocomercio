<?php

namespace App\Http\Controllers;

use App\Services\SupermarketShifts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SupermarketShiftController extends Controller
{
    public function open(Request $request, string $company, SupermarketShifts $shifts): RedirectResponse
    {
        $shift = $shifts->open($request->only('terminal_number', 'opening_balance', 'request_key'), $request->attributes->get('currentUser')->id);

        return redirect()->route('supermarket.index', ['company' => $company])
            ->with('success', 'Abertura registrada para o caixa '.$shift->terminal_number.'.');
    }

    public function close(Request $request, string $company, int $shift, SupermarketShifts $shifts): RedirectResponse
    {
        $closed = $shifts->close($shift, $request->only('counted_balance', 'reason', 'request_key'), $request->attributes->get('currentUser')->id);

        return redirect()->route('supermarket.index', ['company' => $company])
            ->with('success', 'Fechamento registrado para o caixa '.$closed->terminal_number.'.');
    }
}
