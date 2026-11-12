
@extends('layout')
@section('title','Produtos')
@section('subtitle','Catálogo, custo médio e preço de venda em uma única visão.')
@section('actions')
@if($currentUser->role==='admin')<a class="button" href="{{ route('product.new',['company'=>$tenant->slug]) }}">+ Novo produto</a>
@endif
@endsection
@section('content')<section class="panel"><form class="toolbar"><input name="q" value="{{ request('q') }}" placeholder="Buscar por nome, código ou código de barras" aria-label="Buscar produto"><button class="primary">Buscar</button></form><div class="scroll"><table><thead><tr><th>Produto / SKU</th><th>Grupo</th><th class="num">Saldo</th>
@if($currentUser->role==='admin')<th class="num">Custo médio (R$)</th><th class="num">Margem alvo</th><th class="num">Sugerido (R$)</th>
@endif<th class="num">Preço de venda</th><th></th></tr></thead><tbody>
@forelse($products as $p)<tr><td class="product-name"><strong>{{ $p->name }}</strong><br><span class="hint">{{ $p->sku }}
@if(!$p->active)· Inativo
@endif</span></td><td>{{ $p->group->name }}</td><td class="num"><span class="badge {{ $p->quantity<=$p->minimum_stock?'warn':'' }}">{{ number_format($p->quantity,3,',','.') }} {{ $p->unit }}</span></td>
@if($currentUser->role==='admin')<td class="num">{{ number_format($p->average_cost,4,',','.') }}</td><td class="num">{{ $p->margin ?? $p->group->margin }}%</td><td class="num">{{ number_format(\App\Services\Decimal::price($p->average_cost,$p->margin??$p->group->margin),2,',','.') }}</td>
@endif<td class="num">R$ {{ number_format($p->sale_price,2,',','.') }}</td><td>
@if($currentUser->role==='admin')<a href="{{ route('product.edit',['company'=>$tenant->slug,'id'=>$p->id]) }}">Editar</a>
@endif</td></tr>
@empty<tr><td colspan="{{ $currentUser->role==='admin'?8:5 }}" class="empty">Cadastre um grupo e depois seu primeiro produto.</td></tr>
@endforelse</tbody></table></div>{{ $products->links() }}</section>
@endsection
