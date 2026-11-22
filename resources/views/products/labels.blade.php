@extends('layout')
@section('title','Etiquetas de gôndola')
@section('subtitle','Escolha os produtos, informe as quantidades e confira os preços antes de imprimir.')
@section('actions')<a class="button secondary" href="{{ route('products',['company'=>$tenant->slug]) }}">Voltar ao catálogo</a>@endsection
@section('content')
<form method="get" action="{{ route('product.labels',['company'=>$tenant->slug]) }}" class="panel">
    <div class="filter-bar">
        <div class="field"><label for="label-query">Nome, SKU ou código de barras</label><input id="label-query" type="search" name="q" value="{{ $query }}" maxlength="100"></div>
        <button class="button secondary">Buscar produtos</button>
    </div>
    <div class="scroll"><table>
        <thead><tr><th>Produto</th><th>SKU</th><th>Preço de venda</th><th>Etiquetas</th></tr></thead>
        <tbody>
            @foreach($otherSelected->concat($products->items()) as $product)
            <tr>
                <td>{{ $product->name }}</td><td>{{ $product->sku }}</td>
                <td>R$ {{ number_format($product->sale_price,2,',','.') }} / {{ $product->unit }}</td>
                <td><input type="number" min="0" max="200" step="1" name="qty[{{ $product->id }}]" value="{{ $quantities[$product->id] ?? '' }}" placeholder="0" aria-label="Quantidade de etiquetas de {{ $product->name }}" style="width:6rem"></td>
            </tr>
            @endforeach
            @if($products->isEmpty())<tr><td colspan="4" class="empty">Nenhum produto ativo corresponde à busca.</td></tr>@endif
        </tbody>
    </table></div>
    @if($products->lastPage()>1)
    <nav class="actions" aria-label="Páginas de produtos">
        @unless($products->onFirstPage())<button class="button secondary" name="page" value="{{ $products->currentPage()-1 }}">Anterior</button>@endunless
        <span>Página {{ $products->currentPage() }} de {{ $products->lastPage() }}</span>
        @if($products->hasMorePages())<button class="button secondary" name="page" value="{{ $products->currentPage()+1 }}">Próxima</button>@endif
    </nav>
    @endif
    <div class="field"><label for="label-format">Papel</label>
        <select id="label-format" name="format">
            <option value="a4" @selected($format==='a4')>Folha A4 · 10 etiquetas de 95 × 54 mm para recorte</option>
            <option value="roll" @selected($format==='roll')>Bobina · 1 etiqueta de 80 × 50 mm por página</option>
        </select>
    </div>
    <p class="hint">Use zero ou deixe em branco para remover um produto. Cada lote comporta até 50 produtos e 200 etiquetas.</p>
    <button class="button" formaction="{{ route('product.labels.print',['company'=>$tenant->slug]) }}">Conferir impressão</button>
</form>
@endsection
