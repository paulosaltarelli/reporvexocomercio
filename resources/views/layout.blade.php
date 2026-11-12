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
        <small>OPERAÇÃO</small>
        <nav aria-label="Operação comercial">
            @foreach(['dashboard'=>'Visão geral','sales'=>'Vendas','documents'=>'Orçamentos e OS','products'=>'Produtos','stock'=>'Estoque'] as $route=>$label)
                @php($active = match($route) { 'products' => request()->routeIs('products','product.*'), 'sales' => request()->routeIs('sales','sale.*'), 'documents' => request()->routeIs('documents','document.*'), default => request()->routeIs($route) })
                <a class="nav {{ $active ? 'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}" @if($active) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            @foreach(['groups'=>'Grupos e margens','customers'=>'Clientes','suppliers'=>'Fornecedores','services'=>'Serviços'] as $kind=>$label)
                <a class="nav {{ request()->route('kind')===$kind ? 'selected':'' }}" href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}" @if(request()->route('kind')===$kind) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
            @if($currentUser?->role==='admin')
                <a class="nav {{ request()->routeIs('purchases','purchase.*') ? 'selected':'' }}" href="{{ route('purchases',['company'=>$tenant->slug]) }}" @if(request()->routeIs('purchases','purchase.*')) aria-current="page" @endif>Compras</a>
                <a class="nav {{ request()->routeIs('reports','report.*') ? 'selected':'' }}" href="{{ route('reports',['company'=>$tenant->slug]) }}" @if(request()->routeIs('reports','report.*')) aria-current="page" @endif>Relatórios</a>
                <a class="nav {{ request()->routeIs('finance','finance.*') ? 'selected':'' }}" href="{{ route('finance',['company'=>$tenant->slug]) }}" @if(request()->routeIs('finance','finance.*')) aria-current="page" @endif>Contas e recebimentos</a>
            @endif
        </nav>
        @if($currentUser?->role==='admin')
            <small>ADMINISTRAÇÃO</small>
            <nav aria-label="Administração da empresa">
                <a class="nav {{ request()->routeIs('business') ? 'selected':'' }}" href="{{ route('business',['company'=>$tenant->slug]) }}">Minha empresa</a>
                <a class="nav {{ request()->routeIs('users') ? 'selected':'' }}" href="{{ route('users',['company'=>$tenant->slug]) }}" @if(request()->routeIs('users')) aria-current="page" @endif>Usuários e acesso</a>
            </nav>
        @endif
    </div>
    <div class="aside-bottom">Gestão que acontece.<br><span>Vendra · primeira versão</span></div>
</aside>
<div class="workspace">
    <header>
        <span>Seu negócio, em movimento</span>
        <div><span class="current-user">{{ $currentUser?->name }}</span><a class="account-link" href="{{ route('account.password',['company'=>$tenant->slug]) }}">Minha senha</a><form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button class="text-button">Sair</button></form></div>
    </header>
    <main id="main-content" tabindex="-1">
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
