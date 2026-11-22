<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Catálogo de produtos · {{ $storeName }}</title>
    <link rel="stylesheet" href="/storefront.css?v={{ filemtime(public_path('storefront.css')) }}">
</head>
<body>
<header class="brand-bar"><div class="wrap">
    <img src="{{ route('storefront.logo', ['company' => $company], false) }}" alt="" width="116" height="56">
    <strong>{{ $storeName }}</strong>
</div></header>
<main class="wrap">
    <div class="catalog-header">
        <h1>Catálogo de produtos</h1>
        <form method="get" action="{{ route('storefront.index', ['company' => $company], false) }}" role="search">
            <label for="catalog-search">Buscar produto</label>
            <div class="search-row"><input type="search" id="catalog-search" name="q" value="{{ $search }}" maxlength="100" placeholder="Nome do produto"><button>Buscar</button></div>
        </form>
    </div>
    @if($products->isEmpty())
        <div class="empty"><p>{{ $search !== '' ? 'Nenhum produto encontrado para “'.$search.'”.' : 'Nenhum produto nesta página.' }}</p>
            @if($search !== '' || $products->currentPage() > 1)<a href="{{ route('storefront.index', ['company' => $company], false) }}">Ver catálogo completo</a>@endif
        </div>
    @else
        @if($search !== '')<p class="query">Resultados para <strong>{{ $search }}</strong> · <a href="{{ route('storefront.index', ['company' => $company], false) }}">Limpar busca</a></p>@endif
        <ul class="catalog">
            @foreach($products as $product)
            <li class="{{ $product->has_photo ? 'pictured' : '' }}">
                @if($product->has_photo)<img class="photo" src="{{ route('storefront.image', ['company' => $company, 'id' => $product->id], false) }}" alt="" loading="lazy">@endif
                <div class="item"><h2>{{ $product->name }}</h2><p><strong>R$ {{ number_format($product->sale_price, 2, ',', '.') }}</strong><span> / {{ $product->unit }}</span></p></div>
            </li>
            @endforeach
        </ul>
    @endif
    @if($products->hasPages())
        <nav aria-label="Páginas do catálogo">
            @if(!$products->onFirstPage())<a rel="prev" href="{{ $products->previousPageUrl() }}">Anterior</a>@endif
            <span>Página {{ $products->currentPage() }}</span>
            @if($products->hasMorePages())<a rel="next" href="{{ $products->nextPageUrl() }}">Próxima</a>@endif
        </nav>
    @endif
</main>
<footer class="wrap">Vendra por Vexo</footer>
</body></html>
