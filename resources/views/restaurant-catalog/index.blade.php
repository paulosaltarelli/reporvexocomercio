@extends('layout')
@section('title','Cardápio e fichas técnicas')
@section('subtitle','Defina o destino dos pedidos, os adicionais e os ingredientes de cada produto.')
@section('content')
<form class="panel toolbar" method="get"><div class="field"><label for="catalog-q">Buscar produto</label><input id="catalog-q" name="q" maxlength="100" value="{{ $search }}" placeholder="Nome ou SKU"></div><button class="button secondary">Buscar</button></form>
<section class="panel"><div class="scroll"><table><thead><tr><th>Produto</th><th>Cardápio</th><th>Destino padrão</th><th>Estoque no restaurante</th><th>Revisão</th><th></th></tr></thead><tbody>
@forelse($products as $product)
<tr><td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></td><td>{{ ($configs->get($product->id)?->show_on_menu??true)?'Visível':'Oculto' }}</td><td>{{ ($configs->get($product->id)?->station??'kitchen')==='bar'?'Bar':'Cozinha' }}</td><td>{{ ($configs->get($product->id)?->stock_mode??'product')==='recipe'?'Ingredientes da ficha':'Regra do produto' }}</td><td>{{ $configs->get($product->id)?->revision??'Não configurado' }}</td><td><a class="button secondary" href="{{ route('restaurant.catalog.edit',['company'=>$tenant->slug,'product'=>$product->id]) }}">Configurar</a></td></tr>
@empty<tr><td colspan="6" class="empty">Nenhum produto ativo encontrado.</td></tr>@endforelse
</tbody></table></div>{{ $products->links() }}</section>
@endsection
