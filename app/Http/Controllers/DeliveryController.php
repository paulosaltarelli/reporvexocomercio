<?php
namespace App\Http\Controllers;

use App\Delivery\{CatalogMapping, DeliveryOrders, Event, Integration, Order, Outbox, Providers};
use App\Models\Product;
use App\Services\OperationalModes;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class DeliveryController extends Controller
{
    private function requireMode(): void { app(OperationalModes::class)->require('restaurant'); }
    public function index(Request $request)
    {
        $this->requireMode();
        $data=$request->validate(['status'=>'nullable|in:review,accepted,rejected,production,completed,cancelled','provider'=>'nullable|in:ifood,aiqfome,uairango']);
        return view('delivery.index',[
            'providers'=>Providers::LABELS,'integrations'=>Integration::all()->keyBy('provider'),'documentation'=>Providers::DOCUMENTATION,
            'orders'=>Order::where('simulation',true)->when($data['status']??null,fn($q,$v)=>$q->where('status',$v))->when($data['provider']??null,fn($q,$v)=>$q->where('provider',$v))->orderByDesc('id')->paginate(20)->withQueryString(),
            'mappings'=>CatalogMapping::with('product')->orderBy('provider')->orderBy('external_item_id')->paginate(30,['*'],'catalog_page')->withQueryString(),
            'products'=>Product::where('active',true)->where('type','product')->orderBy('name')->get(['id','name','sale_price']),
            'statuses'=>DeliveryOrders::STATUSES,
        ]);
    }
    public function show(Request $request,string $company,int $order)
    {
        $this->requireMode();
        $record=Order::where('simulation',true)->with('items.product')->findOrFail($order);
        return view('delivery.show',['order'=>$record,'events'=>$record->events()->latest('id')->paginate(30),'statuses'=>DeliveryOrders::STATUSES,'providers'=>Providers::LABELS,
            'mappings'=>CatalogMapping::with('product')->where('provider',$record->provider)->whereIn('external_item_id',$record->items->pluck('external_item_id'))->get()->keyBy('external_item_id')]);
    }
    public function settings(Request $request,string $company,string $provider,DeliveryOrders $orders)
    {
        $orders->configure($provider,$request->all(),$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.index',['company'=>$company])->with('success','Configuração de testes atualizada. O provedor permanece aguardando habilitação.');
    }
    public function mapping(Request $request,string $company,string $provider,DeliveryOrders $orders)
    {
        $orders->map($provider,$request->all(),$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.index',['company'=>$company])->with('success','Mapeamento salvo. Pedidos já aceitos preservam seu histórico.');
    }
    public function import(Request $request,DeliveryOrders $orders)
    {
        $request->validate(['payload'=>'required|string|max:250000']);
        try { $payload=json_decode($request->input('payload'),true,64,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw ValidationException::withMessages(['payload'=>'Informe um JSON válido no modelo de teste.']); }
        if (!is_array($payload) || array_is_list($payload)) throw ValidationException::withMessages(['payload'=>'Informe um objeto JSON com os dados do pedido.']);
        $order=$orders->importSimulation($payload,$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.show',['company'=>$request->route('company'),'order'=>$order->id])->with('success','Pedido de teste importado. Nenhuma venda ou cobrança real foi criada.');
    }
    public function transition(Request $request,string $company,int $order,DeliveryOrders $orders)
    {
        $orders->transition($order,$request->all(),$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.show',['company'=>$company,'order'=>$order])->with('success','Etapa do teste atualizada. Nenhuma mensagem real foi enviada ao provedor.');
    }
    public function productionItem(Request $request,string $company,int $order,int $item,DeliveryOrders $orders)
    {
        $orders->productionItem($order,$item,$request->all(),$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.show',['company'=>$company,'order'=>$order])->with('success','Produção de teste atualizada.');
    }
    public function retry(Request $request,string $company,int $event,Outbox $outbox)
    {
        $record=$outbox->retry($event,$request->attributes->get('currentUser')->id);
        return redirect()->route('delivery.show',['company'=>$company,'order'=>$record->delivery_order_id])->with('success','Envio bloqueado: o provedor ainda aguarda habilitação.');
    }
}
