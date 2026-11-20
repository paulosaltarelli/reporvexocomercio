@if($paginator->hasPages())
<nav class="page-controls" aria-label="{{ $label }}">
    @if($paginator->previousPageUrl())<a rel="prev" href="{{ $paginator->previousPageUrl() }}">Anterior</a>@endif
    <span>Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())<a rel="next" href="{{ $paginator->nextPageUrl() }}">Próxima</a>@endif
</nav>
@endif
