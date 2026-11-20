<nav class="central-company-nav" aria-label="Seções da empresa">
    <a href="{{ route('platform.companies.show',$company->id) }}" @if(request()->routeIs('platform.companies.show')) aria-current="page" @endif>Ficha da empresa</a>
    <a href="{{ route('platform.company-access.index',$company->id) }}" @if(request()->routeIs('platform.company-access.*')) aria-current="page" @endif>Gerenciar acessos</a>
    <a href="{{ route('platform.license.index',$company->id) }}" @if(request()->routeIs('platform.license.*')) aria-current="page" @endif>Contratos e parcelas</a>
</nav>
