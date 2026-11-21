<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Product, RestaurantOrder, RestaurantOrderItem, RestaurantTable, Seller};
use App\Services\{CardFees, OperationalModes, PaymentMethods, RestaurantOperations, OperationalReceipt};
use Illuminate\Http\Request;

final class RestaurantController extends Controller
{
    private function requireMode(): void
    {
        app(OperationalModes::class)->require('restaurant');
    }

    public function index()
    {
        $this->requireMode();
        return view('restaurant.index', [
            'tables' => RestaurantTable::where('active', true)->with(['orders' => fn ($query) => $query->where('status', 'open')->with('items')])->orderBy('name')->get(),
            'orders' => RestaurantOrder::with('table', 'customer', 'items')->where('status', 'open')->oldest()->get(),
            'customers' => Customer::orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, string $company, int $order)
    {
        $this->requireMode();
        $record = RestaurantOrder::with('table', 'customer', 'items', 'sale')->findOrFail($order);
        $actor = $request->attributes->get('currentUser');
        return view('restaurant.order', [
            'order' => $record,
            'products' => Product::with('group')->where('active', true)->where('type', 'product')->orderBy('name')->get(),
            'customers' => Customer::orderBy('name')->get(),
            'sellers' => Seller::where('active', true)->orderBy('name')->get(),
            'currentSalesperson' => Seller::where('user_id', $actor->id)->first(),
            'paymentMethods' => app(PaymentMethods::class)->options(),
            'cardBrands' => app(CardFees::class)->options('credit'),
            'statuses' => RestaurantOperations::STATUSES,
            'total' => $record->items->where('status', '<>', 'cancelled')->reduce(fn ($total, $item) => bcadd($total, \App\Services\Decimal::round(bcmul($item->quantity, $item->unit_price, 6), 2), 2), '0.00'),
        ]);
    }

    public function production(Request $request)
    {
        $this->requireMode();
        $data = $request->validate(['station' => 'nullable|in:kitchen,bar']);
        return view('restaurant.production', [
            'items' => RestaurantOrderItem::with('order.table')->whereIn('status', ['queued', 'preparing', 'ready'])
                ->whereHas('order', fn ($query) => $query->where('status', 'open'))
                ->when($data['station'] ?? null, fn ($query, $station) => $query->where('station', $station))
                ->orderBy('sent_at')->orderBy('id')->get(),
            'station' => $data['station'] ?? null, 'statuses' => RestaurantOperations::STATUSES,
        ]);
    }

    public function createTable(Request $request, RestaurantOperations $operations)
    {
        $operations->createTable($request->all(), $request->attributes->get('currentUser')->id);
        return redirect()->route('restaurant.index', ['company' => $request->route('company')])->with('success', 'Mesa cadastrada.');
    }

    public function open(Request $request, RestaurantOperations $operations)
    {
        $order = $operations->open($request->all(), $request->attributes->get('currentUser')->id);
        return redirect()->route('restaurant.orders.show', ['company' => $request->route('company'), 'order' => $order->id]);
    }

    public function addItem(Request $request, string $company, int $order, RestaurantOperations $operations)
    {
        $operations->addItem($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Item adicionado.');
    }

    public function send(Request $request, string $company, int $order, RestaurantOperations $operations)
    {
        $operations->send($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Pedido enviado à produção.');
    }

    public function transitionItem(Request $request, string $company, int $order, int $item, RestaurantOperations $operations)
    {
        $operations->transitionItem($order, $item, $request->all(), $request->attributes->get('currentUser')->id);
        if ($request->input('return_to') === 'production') return redirect()->route('restaurant.production', ['company' => $request->route('company')]);
        return $this->back($request, $order, 'Etapa atualizada.');
    }

    public function deliverItem(Request $request, string $company, int $order, int $item, RestaurantOperations $operations)
    {
        $operations->deliverItem($order, $item, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Entrega registrada.');
    }

    public function cancelItem(Request $request, string $company, int $order, int $item, RestaurantOperations $operations)
    {
        $operations->cancelItem($order, $item, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Item cancelado.');
    }

    public function close(Request $request, string $company, int $order, RestaurantOperations $operations)
    {
        $operations->close($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Comanda encerrada.');
    }

    public function receipt(Request $request, string $company, int $order, OperationalReceipt $receipt)
    {
        $this->requireMode();
        $record = RestaurantOrder::with('sale')->where('status', 'closed')->findOrFail($order);
        $sale = $record->sale;
        $expectedKey = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'vendra:restaurant:'.$record->request_key)->toString();
        abort_unless($sale && (int)$sale->user_id === (int)$request->attributes->get('currentUser')->id
            && $sale->request_key === $expectedKey, 404);

        return $receipt->render($sale, route('restaurant.orders.show', ['company' => $company, 'order' => $record->id]));
    }

    public function cancel(Request $request, string $company, int $order, RestaurantOperations $operations)
    {
        $operations->cancel($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Comanda cancelada.');
    }

    private function back(Request $request, int $order, string $message)
    {
        return redirect()->route('restaurant.orders.show', ['company' => $request->route('company'), 'order' => $order])->with('success', $message);
    }
}
