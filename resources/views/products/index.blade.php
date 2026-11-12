@extends('layout')
@section('title','Produtos e serviços')
@section('subtitle','Um catálogo para os itens vendidos e os serviços prestados, com controle de estoque quando necessário.')
@section('actions')
@if($currentUser->role==='admin')
<div class="actions" style="margin:0"><a class="button secondary" href="{{ route('prices',['company'=>$tenant->slug]) }}">Preços e alertas</a><a class="button" href="{{ route('product.new',['company'=>$tenant->slug,'type'=>request('type')]) }}">+ Novo cadastro</a></div>
@endif
@endsection
@section('content')
<section class="panel">
    <form class="toolbar" method="get">
        <input name="q" value="{{ request('q') }}" placeholder="Buscar por nome, SKU ou código de barras" aria-label="Buscar produto ou serviço">
        <select name="type" class="catalog-type-filter" aria-label="Filtrar tipo do cadastro"><option value="">Todos os tipos</option><option value="product" @selected(request('type')==='product')>Produtos</option><option value="service" @selected(request('type')==='service')>Serviços</option></select>
        <button class="primary">Buscar</button>
    </form>
    <div class="scroll"><table>
        <thead><tr><th>Cadastro / SKU</th><th>Grupo</th><th>Controle de estoque</th><th class="num">Saldo</th>@if($currentUser->role==='admin')<th class="num">Custo base (R$)</th><th class="num">Margem alvo</th><th class="num">Sugerido (R$)</th>@endif<th class="num">Preço de venda</th><th></th></tr></thead>
        <tbody>
        @forelse($products as $product)
            @php($isService=($product->type??'product')==='service')
            @php($stockControlled=!$isService && ($product->controls_stock??true))
            <tr>
                <td class="product-name"><strong>{{ $product->name }}</strong><small class="hint">{{ $product->sku }}</small><span class="badge {{ $isService?'service-type':'' }}">{{ $isService?'Serviço':'Produto' }}</span>@if(!$product->active) <span class="badge bad">Inativo</span>@endif</td>
                <td>{{ $product->group->name }}
                    @if($currentUser->role==='admin')
                        @php($priceMode=$product->price_policy??$product->group->price_policy??'alert')
                        <small class="hint"><span class="badge {{ $priceMode==='automatic'?'':'warn' }}">{{ $priceMode==='automatic'?'Preço automático':'Somente alertas' }}</span></small>
                    @endif
                </td>
                <td>{{ $stockControlled?'Sim':'Não' }}</td>
                <td class="num">@if($stockControlled)<span class="badge {{ $product->quantity<=$product->minimum_stock?'warn':'' }}">{{ number_format($product->quantity,3,',','.') }} {{ $product->unit }}</span>@else<span class="muted">—</span>@endif</td>
                @if($currentUser->role==='admin')
                    @php($baseCost=$stockControlled?$product->average_cost:($product->reference_cost??'0'))
                    <td class="num">{{ number_format($baseCost,4,',','.') }}<small class="hint">{{ $stockControlled?'Custo médio':'Referência' }}</small></td>
                    <td class="num">{{ $product->margin??$product->group->margin }}%</td>
                    <td class="num">
                        @php($review=$product->priceReview)
                        @if($review?->state==='ready' && $review->suggested_price!==null){{ number_format($review->suggested_price,2,',','.') }}
                        @elseif(bccomp((string)$baseCost,'0',4)<=0)<span class="muted">Sem custo</span>
                        @else<a href="{{ route('prices',['company'=>$tenant->slug]) }}">Conferir revisão</a>
                        @endif
                    </td>
                @endif
                <td class="num">R$ {{ number_format($product->sale_price,2,',','.') }}</td>
                <td>@if($currentUser->role==='admin')<a href="{{ route('product.edit',['company'=>$tenant->slug,'id'=>$product->id]) }}">Editar</a>@endif</td>
            </tr>
        @empty
            <tr><td colspan="{{ $currentUser->role==='admin'?9:6 }}" class="empty">Nenhum cadastro encontrado. @if($currentUser->role==='admin')Cadastre um grupo e adicione seus produtos ou serviços.@endif</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $products->links() }}
</section>
@endsection
