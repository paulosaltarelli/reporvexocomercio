<?php
namespace App\Delivery;

use App\Models\{OperationRecord, Product};
use App\Services\{CompanyOperations, Decimal, InstallerAccess, OperationalModes};
use Illuminate\Support\Facades\{DB, Validator};
use Illuminate\Validation\ValidationException;

final class DeliveryOrders
{
    public const STATUSES = ['review'=>'Conferir pedido', 'accepted'=>'Aceito localmente', 'rejected'=>'Recusado', 'production'=>'Em produção', 'completed'=>'Concluído', 'cancelled'=>'Cancelado'];

    private function transaction(int $actor, callable $callback, string $permission = 'delivery.manage')
    {
        return DB::connection('tenant')->transaction(function () use ($actor, $callback, $permission) {
            app(CompanyOperations::class)->lock();
            app(OperationalModes::class)->require('restaurant');
            InstallerAccess::authorizedActor($actor, $permission);
            return $callback();
        }, 3);
    }
    private function invalid(string $field, string $message): never { throw ValidationException::withMessages([$field=>$message]); }
    private function hash(array $payload): string { return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private function audit(string $action, int $actor, array $after): void
    {
        OperationRecord::create(['action'=>$action,'version'=>1,'user_id'=>$actor,'before'=>[],'after'=>$after,'created_at'=>now()]);
    }

    public function configure(string $provider, array $input, int $actor): Integration
    {
        Providers::require($provider);
        $data = Validator::make($input, ['version'=>'required|integer|min:0','manual_test_enabled'=>'required|boolean', 'merchant_reference'=>'nullable|string|max:120','enabled'=>'prohibited','tenant_id'=>'prohibited'])->validate();
        return $this->transaction($actor, function () use ($provider, $data, $actor) {
            $row = Integration::where('provider',$provider)->lockForUpdate()->first();
            if ((int)($row?->version ?? 0) !== (int)$data['version']) $this->invalid('version','A configuração foi alterada. Atualize a página.');
            $row ??= new Integration(['provider'=>$provider,'version'=>0]);
            $before = $row->only(['manual_test_enabled','merchant_reference']);
            $row->fill(['enabled'=>false,'manual_test_enabled'=>(bool)$data['manual_test_enabled'],'merchant_reference'=>$data['merchant_reference'] ?? null,'version'=>$row->version+1])->save();
            $this->audit('delivery_configuration',$actor,['provider'=>$provider,'before'=>$before,'after'=>$row->only(['manual_test_enabled','merchant_reference']),'version'=>$row->version]);
            return $row;
        });
    }

    public function map(string $provider, array $input, int $actor): CatalogMapping
    {
        Providers::require($provider);
        if (isset($input['provider']) && $input['provider'] !== $provider) $this->invalid('provider','O canal escolhido não corresponde a esta ação. Atualize a página e tente novamente.');
        $data = Validator::make($input,['external_item_id'=>'required|string|max:100','product_id'=>'required|integer|min:1','station'=>'required|in:kitchen,bar','version'=>'required|integer|min:0','tenant_id'=>'prohibited'])->validate();
        return $this->transaction($actor,function () use ($provider,$data,$actor) {
            $product = Product::where('active',true)->where('type','product')->sharedLock()->findOrFail($data['product_id']);
            $row = CatalogMapping::where('provider',$provider)->where('external_item_id',$data['external_item_id'])->lockForUpdate()->first();
            if ((int)($row?->version ?? 0) !== (int)$data['version']) $this->invalid('version','Este vínculo foi alterado. Confira o mapeamento atual.');
            $row ??= new CatalogMapping(['provider'=>$provider,'external_item_id'=>$data['external_item_id'],'version'=>0]);
            $row->fill(['product_id'=>$product->id,'station'=>$data['station'],'user_id'=>$actor,'version'=>$row->version+1])->save();
            $this->audit('delivery_mapping',$actor,['mapping_id'=>$row->id,'provider'=>$provider,'external_item_id'=>$row->external_item_id,'product_id'=>$product->id,'version'=>$row->version]);
            return $row;
        });
    }

    /** Authenticated, explicit test ingestion. Tenant comes from the resolved DB, never from the payload. */
    public function importSimulation(array $input, int $actor): Order
    {
        $data = Validator::make($input, [
            'provider'=>'required|in:ifood,aiqfome,uairango','event_id'=>'required|string|max:100','external_id'=>'required|string|max:100',
            'customer_name'=>'required|string|max:160','customer_phone'=>'nullable|string|max:40','delivery_address'=>'nullable|string|max:2000',
            'payment_status'=>'required|in:prepaid,unpaid','delivery_fee'=>'required|numeric','platform_fee'=>'required|numeric','total'=>'required|numeric',
            'items'=>'required|array|min:1|max:200','items.*.external_item_id'=>'required|string|max:100','items.*.description'=>'required|string|max:255',
            'items.*.quantity'=>'required|numeric','items.*.unit_price'=>'required|numeric','items.*.notes'=>'nullable|string|max:1000',
            'tenant_id'=>'prohibited','simulation'=>'prohibited','status'=>'prohibited','user_id'=>'prohibited',
        ])->validate();
        $subtotal = '0.00';
        $items = [];
        foreach ($data['items'] as $n=>$item) {
            $quantity = Decimal::input((string)$item['quantity'],3,"items.$n.quantity",'0.001','999999.999');
            $price = Decimal::input((string)$item['unit_price'],2,"items.$n.unit_price",'0','99999999.99');
            $total = Decimal::round(bcmul($quantity,$price,5),2);
            $subtotal = bcadd($subtotal,$total,2);
            $items[] = ['external_item_id'=>$item['external_item_id'],'description'=>$item['description'],'quantity'=>$quantity,'unit_price'=>$price,'total'=>$total,'notes'=>$item['notes'] ?? null];
        }
        $delivery = Decimal::input((string)$data['delivery_fee'],2,'delivery_fee');
        $fee = Decimal::input((string)$data['platform_fee'],2,'platform_fee');
        $total = Decimal::input((string)$data['total'],2,'total','0.01');
        if (bccomp($total,bcadd($subtotal,$delivery,2),2)!==0 || bccomp($fee,$total,2)>0) $this->invalid('total','O total deve corresponder aos itens mais a entrega, com taxa da plataforma até o valor total.');
        $snapshot = ['provider'=>$data['provider'],'external_id'=>$data['external_id'],'customer_name'=>$data['customer_name'],'customer_phone'=>$data['customer_phone'] ?? null,
            'delivery_address'=>$data['delivery_address'] ?? null,'payment_status'=>$data['payment_status'],'delivery_fee'=>$delivery,'platform_fee'=>$fee,'items_total'=>$subtotal,'total'=>$total,'items'=>$items];
        return $this->transaction($actor, function () use ($data,$snapshot,$actor,$subtotal,$delivery,$fee,$total,$items) {
            $integration = Integration::where('provider',$data['provider'])->lockForUpdate()->first();
            if (!$integration?->manual_test_enabled) $this->invalid('provider','Habilite a importação de teste deste canal antes de continuar.');
            $hash = $this->hash($snapshot);
            $event = Event::where('provider',$data['provider'])->where('direction','inbox')->where('simulation',true)->where('event_key',$data['event_id'])->lockForUpdate()->first();
            if ($event) {
                if (!hash_equals($event->payload_hash,$hash)) $this->invalid('event_id','Este evento já foi usado com outro conteúdo.');
                return $event->order()->firstOrFail();
            }
            $order = Order::where('provider',$data['provider'])->where('simulation',true)->where('external_id',$data['external_id'])->lockForUpdate()->first();
            if ($order && !hash_equals($order->snapshot_hash,$hash)) $this->invalid('external_id','Este pedido já foi importado com outro conteúdo. Confira o pedido existente.');
            if (!$order) {
                $order = Order::create(['provider'=>$data['provider'],'external_id'=>$data['external_id'],'simulation'=>true,'status'=>'review','version'=>1,
                    'payment_status'=>$data['payment_status'],'items_total'=>$subtotal,'delivery_fee'=>$delivery,'platform_fee'=>$fee,'total'=>$total,'expected_payout'=>bcsub($total,$fee,2),
                    'customer_name'=>$data['customer_name'],'customer_phone'=>$data['customer_phone'] ?? null,'delivery_address'=>$data['delivery_address'] ?? null,
                    'snapshot'=>$snapshot,'snapshot_hash'=>$hash,'user_id'=>$actor]);
                foreach ($items as $item) $order->items()->create($item);
                $this->audit('delivery_import_test',$actor,['order_id'=>$order->id,'provider'=>$order->provider,'external_id'=>$order->external_id,'snapshot_hash'=>$hash,'simulation'=>true]);
            }
            Event::create(['provider'=>$order->provider,'direction'=>'inbox','event_key'=>$data['event_id'],'delivery_order_id'=>$order->id,
                'action'=>'manual_test_import','payload_hash'=>$hash,'payload'=>$snapshot,'status'=>'processed','attempts'=>1,'processed_at'=>now(),'user_id'=>$actor]);
            return $order;
        });
    }

    public function transition(int $id, array $input, int $actor): Order
    {
        $data = Validator::make($input,['status'=>'required|in:accepted,rejected,production,completed,cancelled','version'=>'required|integer|min:1','request_key'=>'required|uuid',
            'confirm_prices'=>'nullable|boolean','reason'=>'nullable|string|min:3|max:1000','tenant_id'=>'prohibited'])->validate();
        return $this->transaction($actor,function () use ($id,$data,$actor) {
            $order = Order::lockForUpdate()->findOrFail($id);
            if (!$order->simulation) $this->invalid('order','O processamento de pedidos reais depende da habilitação oficial do canal.');
            $eventKey = 'local:'.$data['request_key'];
            $payload = ['order_id'=>$id,'status'=>$data['status'],'confirm_prices'=>(bool)($data['confirm_prices'] ?? false),'reason'=>$data['reason'] ?? null];
            $hash = $this->hash($payload);
            $event = Event::where('provider',$order->provider)->where('direction','outbox')->where('simulation',true)->where('event_key',$eventKey)->first();
            if ($event) {
                if (!hash_equals($event->payload_hash,$hash) || $event->user_id !== $actor) $this->invalid('request_key','Esta tentativa pertence a outra alteração.');
                return $order;
            }
            if ($order->version !== (int)$data['version']) $this->invalid('version','O pedido foi alterado. Atualize a página e confira a situação.');
            $next = ['review'=>['accepted','rejected','cancelled'],'accepted'=>['production','cancelled'],'production'=>['completed','cancelled']];
            if (!in_array($data['status'],$next[$order->status] ?? [],true)) $this->invalid('status','Esta mudança não corresponde à etapa atual do pedido.');
            if (in_array($data['status'],['rejected','cancelled'],true) && empty($data['reason'])) $this->invalid('reason','Informe o motivo para registrar esta alteração.');
            if ($data['status']==='accepted') $this->acceptItems($order,$data);
            if ($data['status']==='production') {
                $order->items()->where('status','accepted')->update(['status'=>'queued','sent_at'=>now(),'updated_at'=>now()]);
            }
            if ($data['status']==='completed' && $order->items()->whereNotIn('status',['ready','delivered'])->exists()) $this->invalid('status','Todos os itens precisam estar prontos antes de concluir a entrega.');
            if (in_array($data['status'],['cancelled','rejected'],true)) $order->items()->update(['status'=>'cancelled','updated_at'=>now()]);
            $before = $order->status;
            $order->fill(['status'=>$data['status'],'version'=>$order->version+1,'review_reason'=>$data['reason'] ?? $order->review_reason]);
            if ($order->status==='accepted') $order->accepted_at = now();
            if ($order->status==='completed') $order->completed_at = now();
            $order->save();
            Event::create(['provider'=>$order->provider,'direction'=>'outbox','event_key'=>$eventKey,'delivery_order_id'=>$order->id,'action'=>$order->status,
                'payload'=>$payload,'payload_hash'=>$hash,'status'=>$order->simulation?'simulated':'blocked','attempts'=>0,'processed_at'=>$order->simulation?now():null,
                'last_error'=>$order->simulation?null:'Provedor aguardando habilitação oficial.','user_id'=>$actor]);
            $this->audit('delivery_transition',$actor,['order_id'=>$order->id,'from'=>$before,'status'=>$order->status,'version'=>$order->version,'simulation'=>$order->simulation,'reason'=>$data['reason'] ?? null]);
            return $order;
        });
    }

    private function acceptItems(Order $order, array $input): void
    {
        foreach ($order->items()->lockForUpdate()->get() as $item) {
            $mapping = CatalogMapping::where('provider',$order->provider)->where('external_item_id',$item->external_item_id)->sharedLock()->first();
            if (!$mapping) $this->invalid('items','Mapeie todos os itens externos antes de aceitar o pedido.');
            $product = Product::where('active',true)->where('type','product')->sharedLock()->find($mapping->product_id);
            if (!$product) $this->invalid('items','Um produto vinculado está indisponível. Revise o catálogo.');
            if (bccomp($item->unit_price,$product->sale_price,2)!==0 && (empty($input['confirm_prices']) || empty($input['reason']))) {
                $this->invalid('confirm_prices','O preço externo difere do catálogo. Confira os valores e confirme a divergência com justificativa.');
            }
            $item->update(['product_id'=>$product->id,'catalog_unit_price'=>$product->sale_price,'station'=>$mapping->station,'status'=>'accepted',
                'mapping_snapshot'=>['mapping_id'=>$mapping->id,'mapping_version'=>$mapping->version,'product_id'=>$product->id,'product_name'=>$product->name,'catalog_price'=>$product->sale_price,'external_price'=>$item->unit_price,'price_confirmed'=>(bool)($input['confirm_prices'] ?? false)]]);
        }
    }

    public function productionItem(int $orderId, int $itemId, array $input, int $actor): void
    {
        $data = Validator::make($input,['version'=>'required|integer|min:1','status'=>'required|in:preparing,ready'])->validate();
        $this->transaction($actor,function () use ($orderId,$itemId,$data,$actor) {
            $order = Order::lockForUpdate()->findOrFail($orderId);
            if (!$order->simulation) $this->invalid('order','Esta produção é exclusiva para testes isolados.');
            if ($order->status!=='production' || $order->version!==(int)$data['version']) $this->invalid('version','A produção mudou. Atualize a fila antes de continuar.');
            $item = $order->items()->lockForUpdate()->findOrFail($itemId);
            if ((['queued'=>'preparing','preparing'=>'ready'][$item->status] ?? null)!==$data['status']) $this->invalid('status','Confira a etapa atual deste item.');
            $before = $item->status;
            $item->update(['status'=>$data['status']]); $order->increment('version');
            $this->audit('delivery_production',$actor,['order_id'=>$order->id,'item_id'=>$item->id,'from'=>$before,'status'=>$item->status,'simulation'=>$order->simulation]);
        });
    }
}
