<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title','Atendimento') · Vendra</title>
    <link rel="icon" type="image/png" href="/brand/vexo-symbol-navy.png">
    <link rel="stylesheet" href="/app.css?v={{ filemtime(public_path('app.css')) }}">
    <link rel="stylesheet" href="/brand.css?v=20261116-vexo1">
    <link rel="stylesheet" href="/company-brand.css?v={{ filemtime(public_path('company-brand.css')) }}">
    <link rel="stylesheet" href="/operational-fronts.css?v={{ filemtime(public_path('operational-fronts.css')) }}">
    <script src="/company-brand.js?v={{ filemtime(public_path('company-brand.js')) }}" defer></script>
    @stack('styles')
</head>
<body class="operational-shell">
<a class="skip-link" href="#main-content">Ir para o atendimento</a>
<header class="operational-bar" data-operational-header>
    <div class="operational-identity">
        @if(($companyProfile ?? null)?->logo_path)
            @include('components.tenant-brand-mark')
        @else
            <strong class="operational-brand">vendra</strong>
        @endif
        <span>{{ $tenant->name }}</span>
    </div>
    <nav class="operational-navigation" aria-label="Navegação do atendimento">
        @if(app(\App\Services\OperationalModes::class)->enabled('restaurant') && $currentUser->allows('restaurant.use'))
            <a href="{{ route('restaurant.index',['company'=>$tenant->slug]) }}" @if(request()->routeIs('restaurant.index','restaurant.orders.*')) aria-current="page" @endif>Mesas e comandas</a>
        @endif
        @if(app(\App\Services\OperationalModes::class)->enabled('restaurant') && $currentUser->allows('restaurant.kitchen'))
            <a href="{{ route('restaurant.production',['company'=>$tenant->slug]) }}" @if(request()->routeIs('restaurant.production')) aria-current="page" @endif>Cozinha e bar</a>
        @endif
        @if(app(\App\Services\OperationalModes::class)->enabled('supermarket') && $currentUser->allows('supermarket.use'))
            <a href="{{ route('supermarket.index',['company'=>$tenant->slug]) }}" @if(request()->routeIs('supermarket.*')) aria-current="page" @endif>Caixa de supermercado</a>
        @endif
        @if(\Illuminate\Support\Facades\Route::has('supermarket.approvals.index') && app(\App\Services\OperationalModes::class)->enabled('supermarket') && $currentUser->allows('supermarket.supervise'))<a href="{{ route('supermarket.approvals.index',['company'=>$tenant->slug]) }}">Autorizações do caixa</a>@endif
        @if($currentUser->allows('cash.view'))
            <a href="{{ route('cash',['company'=>$tenant->slug]) }}">Caixa diário</a>
        @endif
    </nav>
    <div class="operational-account">
        @if($currentUser->role==='admin' || $currentUser->allows('dashboard.view'))
            <a href="{{ route('dashboard',['company'=>$tenant->slug]) }}">Voltar à gestão</a>
        @endif
        <span class="operational-user-name">{{ $currentUser->name }}</span>
        <form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button type="submit" class="text-button">Sair</button></form>
    </div>
</header>
<main class="operational-workspace" id="main-content" tabindex="-1">
    @if(isset($supportAdmin))<div class="notice support-banner" role="status"><strong>Suporte Vexo · {{ $supportAdmin->name }}</strong><p>{{ $tenant->name }}</p><form method="post" action="{{ route('platform.support.leave') }}">@csrf<button class="button secondary">Encerrar suporte</button></form></div>@endif
    @if(request()->attributes->get('restrictedModules'))<div class="notice error" role="status">Recursos com acesso limitado por pendência da licença. <a href="{{ route('license.help',['company'=>$tenant->slug]) }}">Consultar licença</a></div>@endif
    <div class="page-top"><div><h1>@yield('title','Atendimento')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>
    @if(session('stock_warning'))<div class="notice form-note" role="status">{{ session('stock_warning') }}</div>@endif
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div class="notice error" id="validation-errors" tabindex="-1" role="alert"><strong>Confira os dados para continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        <script id="validation-fields" type="application/json">@json($errors->keys())</script>
    @endif
    @yield('content')
</main>
<footer class="product-owner operational-footer">Vendra · um produto <img src="/brand/vexo-logo-dark.png" width="2172" height="724" alt="Vexo"></footer>
<script src="/ui.js?v={{ filemtime(public_path('ui.js')) }}"></script>
<script src="/operational-fronts.js?v={{ filemtime(public_path('operational-fronts.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
