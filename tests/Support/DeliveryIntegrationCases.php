<?php
namespace Tests\Support;

use App\Delivery\{CatalogMapping, DeliveryOrders, Event, Integration, Order, OrderItem, Outbox};
use App\Models\{FinancialEntry, OperationRecord, Payment, Product, Sale, StockMovement, User};
use App\Services\{CompanyOperations, TenantConnection};
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait DeliveryIntegrationCases
{
    private function enableDeliveryTest(): DeliveryOrders
    {
        app(CompanyOperations::class)->save($this->operationPayload(['segment'=>'restaurant','operation_modes'=>['commerce','restaurant'],'default_operation'=>'restaurant']),$this->user->id);
        $service=app(DeliveryOrders::class);
        $service->configure('ifood',['version'=>0,'manual_test_enabled'=>true,'merchant_reference'=>'merchant-a-test'],$this->user->id);
        return $service;
    }
    private function deliveryPayload(array $replace=[]): array
    {
        return array_replace(['provider'=>'ifood','event_id'=>'event-1','external_id'=>'order-1','customer_name'=>'Cliente de teste','customer_phone'=>'35999999999',
            'delivery_address'=>'Rua de teste, 10','payment_status'=>'prepaid','delivery_fee'=>'5.00','platform_fee'=>'10.00','total'=>'105.00',
            'items'=>[['external_item_id'=>'external-1','description'=>'Nome no canal','quantity'=>'1.000','unit_price'=>'100.00','notes'=>'Observação de teste']]],$replace);
    }
    private function deliveryTransition(DeliveryOrders $service,Order $order,string $status,array $extra=[]): Order
    {
        return $service->transition($order->id,array_replace(['version'=>$order->fresh()->version,'status'=>$status,'request_key'=>(string)Str::uuid()],$extra),$this->user->id);
    }
    private function assertDeliveryInvalid(callable $callback,string $field): void
    {
        try { $callback(); $this->fail('Uma operação inválida de delivery foi aceita.'); }
        catch (ValidationException $e) { $this->assertArrayHasKey($field,$e->errors()); }
    }

    public function test_delivery_simulation_is_idempotent_validates_mapping_and_preserves_approved_price_snapshot(): void
    {
        Http::preventStrayRequests();
        $service=$this->enableDeliveryTest();
        $this->assertFalse(Integration::firstOrFail()->enabled);
        $payload=$this->deliveryPayload();
        $order=$service->importSimulation($payload,$this->user->id);
        $same=$service->importSimulation($payload,$this->user->id);
        $otherEvent=$service->importSimulation(array_replace($payload,['event_id'=>'event-2']),$this->user->id);
        $this->assertSame($order->id,$same->id); $this->assertSame($order->id,$otherEvent->id);
        $this->assertSame(1,Order::count()); $this->assertSame(1,OrderItem::count()); $this->assertSame(2,Event::where('direction','inbox')->count());
        $this->assertDeliveryInvalid(fn()=>$service->importSimulation(array_replace($payload,['customer_name'=>'Outro conteúdo']),$this->user->id),'event_id');
        $badItems=$payload['items']; $badItems[0]['quantity']=['forged'];
        $this->assertDeliveryInvalid(fn()=>$service->importSimulation(array_replace($payload,['items'=>$badItems]),$this->user->id),'items.0.quantity');
        $this->assertDeliveryInvalid(fn()=>$service->importSimulation(array_replace($payload,['event_id'=>'event-3','customer_name'=>'Outro conteúdo']),$this->user->id),'external_id');
        $this->assertDeliveryInvalid(fn()=>$this->deliveryTransition($service,$order,'accepted'),'items');
        $mapping=$service->map('ifood',['version'=>0,'external_item_id'=>'external-1','product_id'=>$this->product->id,'station'=>'kitchen'],$this->user->id);
        $this->product->update(['sale_price'=>'120.00']);
        $this->assertDeliveryInvalid(fn()=>$this->deliveryTransition($service,$order,'accepted'),'confirm_prices');
        $accepted=$this->deliveryTransition($service,$order,'accepted',['confirm_prices'=>true,'reason'=>'Preço promocional conferido']);
        $this->assertSame('accepted',$accepted->status);
        $item=$accepted->items()->firstOrFail();
        $this->assertSame('120.00',$item->catalog_unit_price); $this->assertSame('100.00',$item->unit_price);
        $this->assertSame($mapping->id,$item->mapping_snapshot['mapping_id']); $this->assertTrue($item->mapping_snapshot['price_confirmed']);
        $this->product->update(['sale_price'=>'150.00']);
        $mapping->update(['station'=>'bar','version'=>2]);
        $this->assertSame('120.00',$item->fresh()->catalog_unit_price); $this->assertSame('kitchen',$item->fresh()->station);
        $key=(string)Str::uuid();
        $production=$service->transition($order->id,['version'=>$accepted->version,'status'=>'production','request_key'=>$key],$this->user->id);
        $replay=$service->transition($order->id,['version'=>$accepted->version,'status'=>'production','request_key'=>$key],$this->user->id);
        $this->assertSame($production->version,$replay->version);
        $this->assertSame(1,Event::where('event_key','local:'.$key)->count());
        $this->assertDeliveryInvalid(fn()=>$service->transition($order->id,['version'=>$production->version,'status'=>'cancelled','reason'=>'Mudou a ação','request_key'=>$key],$this->user->id),'request_key');
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get(route('delivery.show',['company'=>'a','order'=>$order->id]))->assertOk()->assertSee('Área isolada')->assertSee('35999999999')->assertSee('Preço divergente');
        Http::assertNothingSent();
    }

    public function test_delivery_simulated_prepaid_and_cancelled_orders_never_affect_cash_sales_stock_or_real_kitchen(): void
    {
        Http::preventStrayRequests();
        $service=$this->enableDeliveryTest();
        $service->map('ifood',['version'=>0,'external_item_id'=>'external-1','product_id'=>$this->product->id,'station'=>'kitchen'],$this->user->id);
        $this->move('in','5','20');
        $before=[$this->product->fresh()->quantity,Sale::count(),FinancialEntry::count(),Payment::count(),StockMovement::count()];
        $order=$service->importSimulation($this->deliveryPayload(),$this->user->id);
        $this->assertSame('95.00',$order->expected_payout);
        $this->deliveryTransition($service,$order,'accepted'); $production=$this->deliveryTransition($service,$order,'production');
        $this->assertDeliveryInvalid(fn()=>$this->deliveryTransition($service,$order,'completed'),'status');
        $item=$order->items()->firstOrFail();
        $service->productionItem($order->id,$item->id,['version'=>$production->version,'status'=>'preparing'],$this->user->id);
        $service->productionItem($order->id,$item->id,['version'=>$order->fresh()->version,'status'=>'ready'],$this->user->id);
        $completed=$this->deliveryTransition($service,$order,'completed');
        $this->assertSame('completed',$completed->status); $this->assertNotNull($completed->completed_at);
        $other=$service->importSimulation($this->deliveryPayload(['event_id'=>'event-unpaid','external_id'=>'order-unpaid','payment_status'=>'unpaid']),$this->user->id);
        $this->deliveryTransition($service,$other,'accepted'); $this->deliveryTransition($service,$other,'production');
        $cancelled=$this->deliveryTransition($service,$other,'cancelled',['reason'=>'Cliente cancelou no teste']);
        $this->assertSame('cancelled',$cancelled->status); $this->assertSame('cancelled',$cancelled->items()->firstOrFail()->status);
        $after=[$this->product->fresh()->quantity,Sale::count(),FinancialEntry::count(),Payment::count(),StockMovement::count()];
        $this->assertSame($before,$after); $this->assertSame(0,\App\Models\RestaurantOrder::count()); $this->assertSame(0,\App\Models\RestaurantOrderItem::count());
        $this->assertTrue(Event::where('direction','outbox')->get()->every(fn($event)=>$event->status==='simulated' && $event->attempts===0));
        $this->assertGreaterThan(0,OperationRecord::where('action','delivery_production')->count());
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get('/app/a/restaurante/producao')->assertOk()->assertDontSee('Nome no canal')->assertDontSee('Observação de teste');
        $this->get('/app/a/integracoes-pedidos')->assertOk()->assertSee('Aguardando habilitação');
        Http::assertNothingSent();
    }

    public function test_delivery_respects_tenant_mode_actor_revocation_and_rejects_forged_live_flags(): void
    {
        $service=$this->enableDeliveryTest();
        $order=$service->importSimulation($this->deliveryPayload(),$this->user->id);
        $this->assertDeliveryInvalid(fn()=>$service->configure('ifood',['version'=>1,'manual_test_enabled'=>true,'enabled'=>true],$this->user->id),'enabled');
        $this->assertDeliveryInvalid(fn()=>$service->importSimulation($this->deliveryPayload(['tenant_id'=>$this->b->id]),$this->user->id),'tenant_id');
        $this->assertDeliveryInvalid(fn()=>$service->importSimulation($this->deliveryPayload(['simulation'=>false]),$this->user->id),'simulation');
        $this->assertFalse(Integration::firstOrFail()->enabled);
        $operator=User::create(['name'=>'Operador','email'=>'delivery-operator@example.test','password'=>'test-password-123','role'=>'operator']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$operator->id,'auth_version'=>0]);
        $this->get('/app/a/integracoes-pedidos')->assertForbidden();
        $this->post('/app/a/integracoes-pedidos/importar-teste',['payload'=>json_encode($this->deliveryPayload())])->assertForbidden();
        $this->withSession(['tenant_id'=>$this->b->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        app(TenantConnection::class)->connect($this->b);
        $userB=User::create(['name'=>'Admin B','email'=>'admin-b-delivery@example.test','password'=>'test-password-123','role'=>'admin']);
        app(CompanyOperations::class)->save($this->operationPayload(['segment'=>'restaurant','operation_modes'=>['restaurant'],'default_operation'=>'restaurant']),$userB->id);
        $this->withSession(['tenant_id'=>$this->b->id,'user_id'=>$userB->id,'auth_version'=>0]);
        $this->get('/app/b/integracoes-pedidos/pedidos/'.$order->id)->assertNotFound();
        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(0,Order::count()); $this->assertSame(0,Integration::count()); $this->assertSame(0,CatalogMapping::count());
        app(TenantConnection::class)->connect($this->a);
        $this->user->update(['active'=>false]);
        try { $service->importSimulation($this->deliveryPayload(['event_id'=>'new-event']),$this->user->id); $this->fail('Ator revogado foi aceito.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { $this->assertSame(403,$e->getStatusCode()); }
        $this->user->update(['active'=>true]);
        app(CompanyOperations::class)->save($this->operationPayload(['version'=>\App\Models\CompanyOperation::findOrFail(1)->version,'segment'=>'commerce','operation_modes'=>['commerce'],'default_operation'=>'commerce']),$this->user->id);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id,'auth_version'=>0]);
        $this->get('/app/a/integracoes-pedidos')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1,Order::count());
    }

    public function test_delivery_outbox_never_calls_disabled_providers_and_enforces_retry_ceiling(): void
    {
        Http::preventStrayRequests();
        $service=$this->enableDeliveryTest();
        $order=$service->importSimulation($this->deliveryPayload(),$this->user->id);
        $event=Event::create(['provider'=>'ifood','direction'=>'outbox','event_key'=>'test-disabled-live-contract','delivery_order_id'=>$order->id,'action'=>'accepted','payload'=>[],'payload_hash'=>hash('sha256','[]'),'status'=>'blocked','attempts'=>0,'user_id'=>$this->user->id]);
        $outbox=app(Outbox::class);
        $this->assertDeliveryInvalid(fn()=>$outbox->retry($event->id,$this->user->id),'event');
        // A future real order cannot bypass provider readiness by modifying a persisted flag.
        $order->update(['simulation'=>false]); $event->update(['simulation'=>false]); Integration::firstOrFail()->update(['enabled'=>true]);
        $blocked=$outbox->retry($event->id,$this->user->id);
        $this->assertSame('blocked',$blocked->status); $this->assertSame(0,$blocked->attempts); $this->assertNull($blocked->next_attempt_at);
        $event->update(['attempts'=>5,'status'=>'failed']);
        $this->assertDeliveryInvalid(fn()=>$outbox->retry($event->id,$this->user->id),'event');
        $event->update(['attempts'=>1,'next_attempt_at'=>now()->addMinute()]);
        $this->assertDeliveryInvalid(fn()=>$outbox->retry($event->id,$this->user->id),'event');
        Http::assertNothingSent();
    }
}
