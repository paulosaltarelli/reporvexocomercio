<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Product, RestaurantOrder, RestaurantOrderItem, RestaurantTable, Seller, OperationRecord};
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
        $tables=RestaurantTable::where('active',true)->with(['orders'=>fn($query)=>$query->where('status','open')->with('items')])->orderBy('name')->get();
        $orders=RestaurantOrder::with('table','customer','items')->where('status','open')->oldest()->get();
        return view('restaurant.index', [
            'tables'=>$tables,'orders'=>$orders,
            'activityState'=>app(\App\Services\RestaurantActivity::class)->index($tables,$orders),
            'customers' => Customer::orderBy('name')->get(),
        ]);
    }

    public function show(Request $request, string $company, int $order)
    {
        $this->requireMode();
        $record = RestaurantOrder::with('table', 'customer', 'items', 'sale')->findOrFail($order);
        $actor = $request->attributes->get('currentUser');
        $products=app(\App\Services\RestaurantCatalog::class)->menuQuery()->with('group')->orderBy('name')->get();
        $catalog=app(\App\Services\RestaurantCatalog::class)->publicCatalog($products);
        $paymentSummary=app(\App\Services\RestaurantPayments::class)->summary($record);
        return view('restaurant.order', [
            'order' => $record,
            'products'=>$products,'catalogByProduct'=>$catalog,'paymentSummary'=>$paymentSummary,
            'activityState'=>app(\App\Services\RestaurantActivity::class)->order($record,$products,$catalog),
            'customers' => Customer::orderBy('name')->get(),
            'sellers' => Seller::where('active', true)->orderBy('name')->get(),
            'currentSalesperson' => Seller::where('user_id', $actor->id)->first(),
            'paymentMethods' => app(PaymentMethods::class)->options(),
            'cardBrands' => app(CardFees::class)->options('credit'),
            'statuses' => RestaurantOperations::STATUSES,
            'availableTables' => RestaurantTable::where('active', true)->whereDoesntHave('orders', fn ($query) => $query->where('status', 'open'))->orderBy('name')->get(),
            'productionBatches' => OperationRecord::where('action', 'restaurant_send')->where('after->order_id', $record->id)->orderByDesc('id')->get(['id', 'created_at']),
            'total'=>$paymentSummary['total'],
        ]);
    }

    public function production(Request $request)
    {
        $this->requireMode();
        $data = $request->validate(['station' => 'nullable|in:kitchen,bar']);
        $items=RestaurantOrderItem::with('order.table')->whereIn('status',['queued','preparing','ready'])
            ->whereHas('order',fn($query)=>$query->where('status','open'))
            ->when($data['station']??null,fn($query,$station)=>$query->where('station',$station))->orderBy('sent_at')->orderBy('id')->get();
        return view('restaurant.production', [
            'items'=>$items,'activityState'=>app(\App\Services\RestaurantActivity::class)->production($items),
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
        $sale=$operations->close($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Comanda encerrada.')->with('stock_warning',app(\App\Services\Inventory::class)->saleWarning($sale->id));
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

    public function transfer(Request $request, string $company, int $order, RestaurantOperations $operations)
    {
        $operations->transfer($order, $request->all(), $request->attributes->get('currentUser')->id);
        return $this->back($request, $order, 'Mesa transferida.');
    }

    public function prebill(Request $request, string $company, int $order)
    {
        $this->requireMode();
        $record = RestaurantOrder::with('table', 'customer', 'items')->findOrFail($order);
        $items = $record->items->where('status', '<>', 'cancelled');
        $total = $items->reduce(fn ($sum, $item) => bcadd($sum, (string)$item->commercial_terms['total'], 2), '0.00');
        return response()->view('restaurant.prebill', ['order' => $record, 'items' => $items, 'total' => $total,
            'paymentSummary'=>app(\App\Services\RestaurantPayments::class)->summary($record),
            'receiptSale' => true, 'receiptBackUrl' => route('restaurant.orders.show', ['company' => $company, 'order' => $record->id])])
            ->header('Cache-Control', 'private, no-store');
    }

    public function ticket(Request $request, string $company, int $order)
    {
        $this->requireMode();
        $data = $request->validate(['batch' => 'nullable|integer|min:1', 'station' => 'nullable|in:kitchen,bar']);
        $record = RestaurantOrder::with('table', 'customer')->findOrFail($order);
        $query = OperationRecord::where('action', 'restaurant_send')->where('after->order_id', $record->id);
        $batch = isset($data['batch']) ? $query->findOrFail($data['batch']) : $query->latest('id')->first();
        $items = $record->items()->whereIn('id', $batch?->after['item_ids'] ?? [])
            ->when($data['station'] ?? null, fn ($query, $station) => $query->where('station', $station))->orderBy('id')->get();
        $backRoute = $request->attributes->get('currentUser')->allows('restaurant.use') ? 'restaurant.orders.show' : 'restaurant.production';
        $backParameters = ['company' => $company];
        if ($backRoute === 'restaurant.orders.show') $backParameters['order'] = $record->id;
        return response()->view('restaurant.ticket', ['order' => $record, 'items' => $items, 'batch' => $batch,
            'station' => $data['station'] ?? null, 'receiptSale' => true, 'receiptBackUrl' => route($backRoute, $backParameters)])
            ->header('Cache-Control', 'private, no-store');
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
