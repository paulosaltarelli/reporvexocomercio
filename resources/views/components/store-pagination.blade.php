@if($paginator->hasPages())
<nav class="store-pager no-print" aria-label="Páginas da listagem">
    @if($paginator->previousPageUrl())<a rel="prev" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
    @foreach($elements as $element)
        @if(is_string($element))<span class="store-page-number" aria-hidden="true">…</span>@else
            @foreach($element as $number=>$url)
                @if($paginator->currentPage()===$number)<span class="store-page-number" aria-current="page" aria-label="Página {{ $number }}">{{ $number }}</span>
                @else<a class="store-page-number" href="{{ $url }}" aria-label="Página {{ $number }}">{{ $number }}</a>@endif
            @endforeach
        @endif
    @endforeach
    <span class="store-page-position">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a rel="next" href="{{ $paginator->nextPageUrl() }}">Próxima</a>@endif
</nav>
@endif
