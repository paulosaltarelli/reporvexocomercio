<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title','Visão geral') · Vendra</title>
    <link rel="stylesheet" href="/app.css">
</head>
<body>
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<aside aria-label="Navegação da empresa">
    <div class="sidebar-heading">
        <a class="brand" href="{{ route('dashboard',['company'=>$tenant->slug]) }}" aria-label="Vendra por Vexo, visão geral">vendra<span>por Vexo</span></a>
        <button type="button" class="menu-toggle" aria-controls="company-navigation" aria-expanded="true">Menu <span aria-hidden="true">☰</span></button>
    </div>
    <div class="company">{{ $tenant->name }}</div>
    <div id="company-navigation" class="sidebar-menu">
        @php($registrationNavigation=request()->routeIs('products','product.*','catalog'))
        @php($operationNavigation=request()->routeIs('sales','sale.*','documents','document.*','stock','prices','price.*','purchases','purchase.*'))
        @php($financeNavigation=request()->routeIs('finance','finance.*','cashflow','cash','cash.*'))
        @php($reportNavigation=request()->routeIs('reports','report.*'))
        @php($settingsNavigation=request()->routeIs('business','users','account.password','fiscal','fiscal.*'))
        <nav aria-label="Menu da empresa" class="grouped-navigation">
            <a class="nav {{ request()->routeIs('dashboard')?'selected':'' }}" href="{{ route('dashboard',['company'=>$tenant->slug]) }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Visão geral</a>
            <details class="nav-group {{ $registrationNavigation?'current-group':'' }}" @if($registrationNavigation) open @endif>
                <summary>Cadastros</summary>
                <div class="nav-group-links">
                    <a class="nav {{ request()->routeIs('products','product.*')?'selected':'' }}" href="{{ route('products',['company'=>$tenant->slug]) }}" @if(request()->routeIs('products','product.*')) aria-current="page" @endif>Produtos e serviços</a>
                    @foreach(['groups'=>($currentUser?->role==='admin'?'Grupos e margens':'Grupos'),'customers'=>'Clientes','suppliers'=>'Fornecedores'] as $kind=>$label)
                        <a class="nav {{ request()->route('kind')===$kind?'selected':'' }}" href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}" @if(request()->route('kind')===$kind) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                </div>
            </details>
            <details class="nav-group {{ $operationNavigation?'current-group':'' }}" @if($operationNavigation) open @endif>
                <summary>Operações</summary>
                <div class="nav-group-links">
                    @foreach(['sales'=>'Vendas','documents'=>'Orçamentos e OS','stock'=>'Movimentações de estoque'] as $route=>$label)
                        @php($active=match($route){'sales'=>request()->routeIs('sales','sale.*'),'documents'=>request()->routeIs('documents','document.*'),default=>request()->routeIs($route)})
                        <a class="nav {{ $active?'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                    @if($currentUser?->role==='admin')
                        <a class="nav {{ request()->routeIs('purchases','purchase.*')?'selected':'' }}" href="{{ route('purchases',['company'=>$tenant->slug]) }}" @if(request()->routeIs('purchases','purchase.*')) aria-current="page" @endif>Compras</a>
                        <a class="nav {{ request()->routeIs('prices','price.*')?'selected':'' }}" href="{{ route('prices',['company'=>$tenant->slug]) }}" @if(request()->routeIs('prices','price.*')) aria-current="page" @endif>Preços e alertas</a>
                    @endif
                </div>
            </details>
            @if($currentUser?->role==='admin')
                <details class="nav-group {{ $financeNavigation?'current-group':'' }}" @if($financeNavigation) open @endif>
                    <summary>Financeiro</summary>
                    <div class="nav-group-links">
                        @foreach(['finance'=>'Contas e recebimentos','cashflow'=>'Fluxo e previsão','cash'=>'Caixa diário'] as $route=>$label)
                            @php($active=match($route){'cash'=>request()->routeIs('cash','cash.*'),'finance'=>request()->routeIs('finance','finance.*'),default=>request()->routeIs($route)})
                            <a class="nav {{ $active?'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                        @endforeach
                    </div>
                </details>
                <details class="nav-group {{ $reportNavigation?'current-group':'' }}" @if($reportNavigation) open @endif>
                    <summary>Relatórios</summary>
                    <div class="nav-group-links"><a class="nav {{ $reportNavigation?'selected':'' }}" href="{{ route('reports',['company'=>$tenant->slug]) }}" @if($reportNavigation) aria-current="page" @endif>Visão gerencial e exportações</a></div>
                </details>
                <details class="nav-group {{ $settingsNavigation?'current-group':'' }}" @if($settingsNavigation) open @endif>
                    <summary>Administração</summary>
                    <div class="nav-group-links">
                        <a class="nav {{ request()->routeIs('business')?'selected':'' }}" href="{{ route('business',['company'=>$tenant->slug]) }}" @if(request()->routeIs('business')) aria-current="page" @endif>Minha empresa</a>
                        <a class="nav {{ request()->routeIs('fiscal','fiscal.*')?'selected':'' }}" href="{{ route('fiscal',['company'=>$tenant->slug]) }}">Fiscal</a>
                        <a class="nav {{ request()->routeIs('users')?'selected':'' }}" href="{{ route('users',['company'=>$tenant->slug]) }}" @if(request()->routeIs('users')) aria-current="page" @endif>Usuários e acesso</a>
                    </div>
                </details>
            @endif
        </nav>

    </div>
    <div class="aside-bottom">Gestão que acontece.<br><span>Vendra · primeira versão</span></div>
</aside>
<div class="workspace">
    <header>
        <span>Seu negócio, em movimento</span>
        <div><span class="current-user">{{ $currentUser?->name }}</span><a class="account-link" href="{{ route('account.password',['company'=>$tenant->slug]) }}">Minha senha</a><form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button class="text-button">Sair</button></form></div>
    </header>
    <main id="main-content" tabindex="-1">
        @include('components.company-report-header',['reportPrintOnly'=>!request()->routeIs('reports','sale.show','document.show','purchase.show','cash','cashflow','finance')])
        <div class="page-top"><div><div class="eyebrow">VENDRA / GESTÃO COMERCIAL</div><h1>@yield('title','Visão geral')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>
        @if(session('success'))
            <div class="notice success" role="status">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="notice error" id="validation-errors" tabindex="-1" role="alert"><strong>Confira os dados antes de continuar:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            <script id="validation-fields" type="application/json">@json($errors->keys())</script>
        @endif
        @yield('content')
    </main>
    <footer>Um produto Vexo · NFS-e ainda não disponível</footer>
</div>
<script src="/ui.js"></script>
@stack('scripts')
</body>
</html>
