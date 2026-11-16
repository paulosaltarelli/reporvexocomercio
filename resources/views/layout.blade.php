<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title','Visão geral') · Vendra</title>
    <link rel="stylesheet" href="/app.css?v={{ filemtime(public_path('app.css')) }}">
    @if(request()->routeIs('setup*'))<link rel="stylesheet" href="/company-setup.css">@endif
</head>
<body>
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<aside aria-label="Navegação da empresa">
    <div class="sidebar-heading">
        <a class="brand" href="{{ route($currentUser?->role==='admin'?'dashboard':'desk',['company'=>$tenant->slug]) }}" aria-label="Vendra por Vexo, início">vendra<span>por Vexo</span></a>
        <button type="button" class="menu-toggle" aria-controls="company-navigation" aria-expanded="true">Menu <span aria-hidden="true">☰</span></button>
    </div>
    <div class="company">{{ $tenant->name }}</div>
    <div id="company-navigation" class="sidebar-menu">
        @php($registrationNavigation=request()->routeIs('products','product.*','catalog','sellers'))
        @php($operationNavigation=request()->routeIs('sales','sale.*','documents','document.*'))
        @php($stockNavigation=request()->routeIs('stock','prices','price.*','purchases','purchase.*'))
        @php($fiscalNavigation=request()->routeIs('fiscal','fiscal.*','nfe.*','nfse.*'))
        @php($financeNavigation=request()->routeIs('finance','finance.*','cashflow','cash','cash.*'))
        @php($reportNavigation=request()->routeIs('reports','report.*','commissions'))
        @php($settingsNavigation=request()->routeIs('business','setup*','users','account.password'))
        <nav aria-label="Menu da empresa" class="grouped-navigation">
            <a class="nav {{ request()->routeIs('desk')?'selected':'' }}" href="{{ route('desk',['company'=>$tenant->slug]) }}" @if(request()->routeIs('desk')) aria-current="page" @endif>Painel de operação</a>
            @if($currentUser?->role==='admin')
            <a class="nav {{ request()->routeIs('dashboard')?'selected':'' }}" href="{{ route('dashboard',['company'=>$tenant->slug]) }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif>Visão geral</a>
            @endif
            <details class="nav-group {{ $registrationNavigation?'current-group':'' }}" @if($registrationNavigation) open @endif>
                <summary>Cadastros</summary>
                <div class="nav-group-links">
                    <a class="nav {{ request()->routeIs('products','product.*')?'selected':'' }}" href="{{ route('products',['company'=>$tenant->slug]) }}" @if(request()->routeIs('products','product.*')) aria-current="page" @endif>Produtos e serviços</a>
                    @foreach(['groups'=>($currentUser?->role==='admin'?'Grupos e margens':'Grupos'),'customers'=>'Clientes','suppliers'=>'Fornecedores'] as $kind=>$label)
                        <a class="nav {{ request()->route('kind')===$kind?'selected':'' }}" href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}" @if(request()->route('kind')===$kind) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                    @if($currentUser?->role==='admin')<a class="nav {{ request()->routeIs('sellers')?'selected':'' }}" href="{{ route('sellers',['company'=>$tenant->slug]) }}" @if(request()->routeIs('sellers')) aria-current="page" @endif>Vendedores</a>@endif
                </div>
            </details>
            <details class="nav-group {{ $operationNavigation?'current-group':'' }}" @if($operationNavigation) open @endif>
                <summary>Atendimento</summary>
                <div class="nav-group-links">
                    @foreach(['sales'=>'Vendas','documents'=>'Orçamentos e OS'] as $route=>$label)
                        @php($active=match($route){'sales'=>request()->routeIs('sales','sale.*'),'documents'=>request()->routeIs('documents','document.*'),default=>request()->routeIs($route)})
                        <a class="nav {{ $active?'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                    @endforeach
                    @if($operationConfig['work_orders'])<a class="nav nav-shortcut" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'work_order']) }}">+ Nova ordem de serviço</a>@endif
                    @if($operationConfig['quotes'])<a class="nav nav-shortcut" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'quote']) }}">+ Novo orçamento</a>@endif
                </div>
            </details>
            <details class="nav-group {{ $stockNavigation?'current-group':'' }}" @if($stockNavigation) open @endif><summary>Estoque e compras</summary><div class="nav-group-links">
                @if($operationConfig['inventory'])<a class="nav {{ request()->routeIs('stock')?'selected':'' }}" href="{{ route('stock',['company'=>$tenant->slug]) }}" @if(request()->routeIs('stock')) aria-current="page" @endif>Movimentações de estoque</a>@endif
                @if($currentUser?->role==='admin')
                @foreach(['purchases'=>'Compras','prices'=>'Preços e alertas'] as $route=>$label)
                @php($active=$route==='purchases'?request()->routeIs('purchases','purchase.*'):request()->routeIs('prices','price.*'))
                <a class="nav {{ $active?'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
                @endif
            </div></details>
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
                <details class="nav-group {{ $fiscalNavigation?'current-group':'' }}" @if($fiscalNavigation) open @endif><summary>Fiscal</summary><div class="nav-group-links">
                @php($sefazActive=request()->routeIs('fiscal','nfe.*','fiscal.document','fiscal.sale','fiscal.preparation','fiscal.product'))
                <a class="nav {{ $sefazActive?'selected':'' }}" href="{{ route('fiscal',['company'=>$tenant->slug]) }}" @if($sefazActive) aria-current="page" @endif>Documentos e configuração</a>
                <a class="nav {{ request()->routeIs('nfse.*')?'selected':'' }}" href="{{ route('nfse.index',['company'=>$tenant->slug]) }}" @if(request()->routeIs('nfse.*')) aria-current="page" @endif>NFS-e nacional</a>
                <a class="nav {{ request()->routeIs('fiscal.profiles.*')?'selected':'' }}" href="{{ route('fiscal.profiles.index',['company'=>$tenant->slug]) }}" @if(request()->routeIs('fiscal.profiles.*')) aria-current="page" @endif>Perfis tributários</a>
                <a class="nav {{ request()->routeIs('fiscal.simulations.*')?'selected':'' }}" href="{{ route('fiscal.simulations.index',['company'=>$tenant->slug]) }}" @if(request()->routeIs('fiscal.simulations.*')) aria-current="page" @endif>Simulações internas</a>
                </div></details>
                <details class="nav-group {{ $reportNavigation?'current-group':'' }}" @if($reportNavigation) open @endif>
                    <summary>Relatórios</summary>
                    <div class="nav-group-links"><a class="nav {{ request()->routeIs('reports','report.*')?'selected':'' }}" href="{{ route('reports',['company'=>$tenant->slug]) }}" @if(request()->routeIs('reports','report.*')) aria-current="page" @endif>Visão gerencial e exportações</a>@if($operationConfig['commissions'])<a class="nav {{ request()->routeIs('commissions')?'selected':'' }}" href="{{ route('commissions',['company'=>$tenant->slug]) }}" @if(request()->routeIs('commissions')) aria-current="page" @endif>Comissões por vendedor</a>@endif</div>
                </details>
                <details class="nav-group {{ $settingsNavigation?'current-group':'' }}" @if($settingsNavigation) open @endif>
                    <summary>Administração</summary>
                    <div class="nav-group-links">
                        <a class="nav {{ request()->routeIs('setup*')?'selected':'' }}" href="{{ route('setup',['company'=>$tenant->slug]) }}" @if(request()->routeIs('setup*')) aria-current="page" @endif>Operação e implantação</a>
                        <a class="nav {{ request()->routeIs('business')?'selected':'' }}" href="{{ route('business',['company'=>$tenant->slug]) }}" @if(request()->routeIs('business')) aria-current="page" @endif>Minha empresa</a>
                        <a class="nav {{ request()->routeIs('users')?'selected':'' }}" href="{{ route('users',['company'=>$tenant->slug]) }}" @if(request()->routeIs('users')) aria-current="page" @endif>Usuários e acesso</a>
                    </div>
                </details>
            @endif
        </nav>

    </div>
    <div class="aside-bottom">Gestão que acontece.<br><span>Vendra por Vexo</span></div>
</aside>
<div class="workspace">
    <header>
        <span>Seu negócio, em movimento</span>
        <div><span class="current-user">{{ $currentUser?->name }}</span><a class="account-link" href="{{ route('account.password',['company'=>$tenant->slug]) }}">Minha senha</a><form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button class="text-button">Sair</button></form></div>
    </header>
    <main id="main-content" tabindex="-1">
        @include('components.company-report-header',['reportPrintOnly'=>!request()->routeIs('reports','commissions','sale.show','document.show','purchase.show','cash','cashflow','finance')])
        <div class="page-top"><div><div class="eyebrow">VENDRA / GESTÃO COMERCIAL</div><h1>@yield('title','Visão geral')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>
        @if(session('success'))
            <div class="notice success" role="status">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="notice error" id="validation-errors" tabindex="-1" role="alert"><strong>Confira os dados antes de continuar:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            <script id="validation-fields" type="application/json">@json($errors->keys())</script>
        @endif
        @if($currentUser?->role==='admin' && request()->routeIs('dashboard','desk') && !$companySetup?->completed_at)
            <div class="notice" role="status">Confira a implantação desta empresa. <a href="{{ route('setup',['company'=>$tenant->slug]) }}">Configurar operação e acompanhar etapas</a></div>
        @endif
        @yield('content')
    </main>
    <footer>Vendra · um produto Vexo</footer>
</div>
<script src="/ui.js?v={{ filemtime(public_path('ui.js')) }}"></script>
@stack('scripts')
</body>
</html>
