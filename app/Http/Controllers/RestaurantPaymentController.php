<?php

namespace App\Http\Controllers;

use App\Services\RestaurantPayments;
use Illuminate\Http\Request;

final class RestaurantPaymentController extends Controller
{
    public function store(Request $request, string $company, int $order, RestaurantPayments $payments)
    {
        $payments->receive($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($company, $order, 'Recebimento registrado na comanda e no caixa.');
    }

    public function divide(Request $request, string $company, int $order, RestaurantPayments $payments)
    {
        $payments->divide($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($company, $order, 'Divisão por pessoas registrada. Os valores serão recebidos individualmente.');
    }

    public function cancelDivision(Request $request, string $company, int $order, string $division, RestaurantPayments $payments)
    {
        $payments->cancelDivision($order, $division, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($company, $order, 'Divisão cancelada. Os recebimentos já registrados foram preservados.');
    }

    public function refund(Request $request, string $company, int $order, int $payment, RestaurantPayments $payments)
    {
        $payments->refund($order, $payment, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($company, $order, 'Estorno confirmado registrado no caixa. As taxas de cartão permanecem como despesas.');
    }

    private function back(string $company, int $order, string $message)
    {
        return redirect()->route('restaurant.orders.show', ['company' => $company, 'order' => $order])->with('success', $message);
    }
}
