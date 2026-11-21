@extends('layout')
@section('title','Integrações de pedidos')
@section('subtitle','Prepare o catálogo e valide o fluxo de delivery antes de conectar as plataformas.')
@section('content')
<section class="panel">
<h2>Conexão com os canais</h2>
<p class="hint">iFood, aiqfome e UaiRango aguardam cadastro de desenvolvedor, autorização da loja e homologação. Esta versão permite testar a importação e a produção em uma área separada. Nenhum pedido de teste entra no caixa, nas vendas, no estoque ou na cozinha real.</p>
<div class="form-grid">
@foreach($providers as $key=>$label)
@php($integration=$integrations->get($key))
<article class="panel"><h3>{{ $label }}</h3><p><span class="badge">Aguardando habilitação</span></p><p class="hint">Não conectado · nenhum envio ou recebimento automático.</p>
@if($currentUser->allows('delivery.manage'))
<form method="post" action="{{ route('delivery.settings',['company'=>$tenant->slug,'provider'=>$key]) }}">@csrf
<input type="hidden" name="version" value="{{ $integration?->version ?? 0 }}">
<div class="field"><label for="merchant-{{ $key }}">Identificação da loja no canal (opcional)</label><input id="merchant-{{ $key }}" name="merchant_reference" maxlength="120" value="{{ $integration?->merchant_reference }}" placeholder="Referência da loja, sem senhas ou tokens"></div>
<input type="hidden" name="manual_test_enabled" value="0"><label class="checkbox-line"><input type="checkbox" name="manual_test_enabled" value="1" @checked($integration?->manual_test_enabled)> Permitir importações de teste</label>
<div class="actions"><button class="primary">Salvar configuração de teste</button></div>
</form>
@endif
<a href="{{ $documentation[$key] }}" rel="noopener noreferrer" target="_blank">Consultar documentação oficial</a>
</article>
@endforeach
</div>
</section>
<section class="panel"><h2>Pedidos de teste</h2>
<form method="get" class="form-grid"><div class="field"><label for="delivery-provider">Canal</label><select id="delivery-provider" name="provider"><option value="">Todos os canais</option>@foreach($providers as $key=>$label)<option value="{{ $key }}" @selected(request('provider')===$key)>{{ $label }}</option>@endforeach</select></div><div class="field"><label for="delivery-status">Etapa</label><select id="delivery-status" name="status"><option value="">Todas as etapas</option>@foreach($statuses as $key=>$label)<option value="{{ $key }}" @selected(request('status')===$key)>{{ $label }}</option>@endforeach</select></div><div class="actions"><button class="secondary">Filtrar pedidos</button></div></form>
<div class="table-wrap"><table><thead><tr><th>Pedido externo</th><th>Canal</th><th>Cliente</th><th>Etapa local</th><th>Total simulado</th><th></th></tr></thead><tbody>@forelse($orders as $order)<tr><td>{{ $order->external_id }}</td><td>{{ $providers[$order->provider] }}</td><td>{{ $order->customer_name }}</td><td>{{ $statuses[$order->status] }}</td><td>R$ {{ number_format($order->total,2,',','.') }}</td><td><a class="button secondary" href="{{ route('delivery.show',['company'=>$tenant->slug,'order'=>$order->id]) }}">Conferir teste</a></td></tr>@empty<tr><td colspan="6">Nenhum teste importado. Vincule o catálogo e importe o primeiro pedido abaixo.</td></tr>@endforelse</tbody></table></div>{{ $orders->links() }}
</section>
<section class="panel" id="delivery-catalog"><h2>Mapeamento do catálogo</h2><p class="hint">Vincule o código do item no canal a um produto ativo da loja. O preço recebido é preservado no pedido e qualquer divergência exige conferência antes da aceitação. O mapeamento não publica o catálogo nos canais.</p>
@if($currentUser->allows('delivery.manage'))
<form method="post" action="{{ route('delivery.mapping',['company'=>$tenant->slug,'provider'=>array_key_first($providers)]) }}" data-delivery-map>@csrf<input type="hidden" name="version" value="0"><div class="form-grid">
<div class="field"><label for="map-provider">Canal</label><select id="map-provider" name="provider" data-provider-base="{{ url('app/'.$tenant->slug.'/integracoes-pedidos/provedores') }}">@foreach($providers as $key=>$label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
<div class="field"><label for="external-item">Código do item no canal</label><input id="external-item" name="external_item_id" maxlength="100" required></div>
<div class="field"><label for="mapped-product">Produto da loja</label><select id="mapped-product" name="product_id" required>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->name }} · R$ {{ number_format($product->sale_price,2,',','.') }}</option>@endforeach</select></div>
<div class="field"><label for="map-station">Produção de teste</label><select id="map-station" name="station"><option value="kitchen">Cozinha</option><option value="bar">Bar</option></select></div>
</div><div class="actions"><button class="primary">Vincular item</button></div></form>
@endif
<div class="table-wrap"><table><thead><tr><th>Canal</th><th>Código externo</th><th>Produto</th><th>Destino</th><th>Versão</th></tr></thead><tbody>@forelse($mappings as $mapping)<tr><td>{{ $providers[$mapping->provider] }}</td><td>{{ $mapping->external_item_id }}</td><td>{{ $mapping->product?->name ?? 'Produto indisponível' }}</td><td>{{ $mapping->station==='bar'?'Bar':'Cozinha' }}</td><td>{{ $mapping->version }}
@if($currentUser->allows('delivery.manage'))<details class="disclosure"><summary>Editar vínculo</summary><form method="post" action="{{ route('delivery.mapping',['company'=>$tenant->slug,'provider'=>$mapping->provider]) }}">@csrf<input type="hidden" name="version" value="{{ $mapping->version }}"><input type="hidden" name="external_item_id" value="{{ $mapping->external_item_id }}"><div class="field"><label>Produto vinculado</label><select name="product_id" required>@foreach($products as $product)<option value="{{ $product->id }}" @selected($mapping->product_id===$product->id)>{{ $product->name }}</option>@endforeach</select></div><div class="field"><label>Destino de teste</label><select name="station"><option value="kitchen" @selected($mapping->station==='kitchen')>Cozinha</option><option value="bar" @selected($mapping->station==='bar')>Bar</option></select></div><button class="secondary">Salvar vínculo</button></form></details>@endif
</td></tr>@empty<tr><td colspan="5">Nenhum vínculo cadastrado.</td></tr>@endforelse</tbody></table></div>{{ $mappings->links() }}
</section>
@if($currentUser->allows('delivery.manage'))
<section class="panel"><h2>Importar pedido de teste</h2><p class="hint">Use dados fictícios. Copie o modelo e ajuste os códigos para os itens mapeados. Repetir um evento idêntico mantém um único pedido; o mesmo código com conteúdo diferente é rejeitado.</p>
@php($example=['provider'=>'ifood','event_id'=>'teste-evento-001','external_id'=>'teste-pedido-001','customer_name'=>'Cliente de teste','customer_phone'=>'','delivery_address'=>'Endereço fictício para teste','payment_status'=>'prepaid','delivery_fee'=>'0.00','platform_fee'=>'0.00','total'=>'100.00','items'=>[['external_item_id'=>'item-teste-1','description'=>'Produto de teste','quantity'=>'1.000','unit_price'=>'100.00','notes'=>'Sem observações']]])
<details class="disclosure"><summary>Modelo de pedido JSON</summary><pre class="disclosure-content delivery-json" tabindex="0">{{ json_encode($example,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) }}</pre></details>
<form method="post" action="{{ route('delivery.import',['company'=>$tenant->slug]) }}">@csrf<div class="field"><label for="delivery-payload">Dados do teste (JSON)</label><textarea id="delivery-payload" name="payload" rows="14" maxlength="250000" required spellcheck="false">{{ old('payload') }}</textarea></div><button class="primary">Importar na área de testes</button></form>
</section>
@endif
@endsection
@push('scripts')<script src="{{ asset('delivery-admin.js') }}?v={{ filemtime(public_path('delivery-admin.js')) }}" defer></script>@endpush

@push('styles')<link rel="stylesheet" href="{{ asset('delivery-admin.css') }}?v={{ filemtime(public_path('delivery-admin.css')) }}">@endpush
