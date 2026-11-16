<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title','Visão geral') · Vendra</title>
    <link rel="stylesheet" href="/app.css?v={{ filemtime(public_path('app.css')) }}">
    <link rel="stylesheet" href="/navigation.css?v={{ filemtime(public_path('navigation.css')) }}">
    @if(request()->routeIs('desk','sale.new','document.show'))<link rel="stylesheet" href="/pos.css?v={{ filemtime(public_path('pos.css')) }}">@endif
    @if(request()->routeIs('dashboard','commissions*','commission.*','payment.methods*'))<link rel="stylesheet" href="/commercial-insights.css?v={{ filemtime(public_path('commercial-insights.css')) }}">@endif
    @if(request()->routeIs('access.profiles*'))<link rel="stylesheet" href="/access-profiles.css?v={{ filemtime(public_path('access-profiles.css')) }}">@endif
    @if(request()->routeIs('setup*'))<link rel="stylesheet" href="/company-setup.css?v={{ filemtime(public_path('company-setup.css')) }}">@endif
<link rel="stylesheet" href="/brand.css?v=20261116-vexo1"></head>
<body @if($wizardMode??false) class="onboarding-shell" @endif>
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
@if(!($wizardMode??false))@include('partials.company-navigation')@endif
<div class="workspace">
    <header>
        <div class="workspace-location"><button type="button" class="menu-toggle" aria-controls="company-navigation" aria-expanded="true" aria-label="Abrir menu"><x-navigation-icon name="menu"/></button><span class="workspace-section">@yield('navigation-section','Gestão')</span><x-navigation-icon name="chevron"/><span class="workspace-page">@yield('title','Visão geral')</span></div>
        <div class="workspace-account"><span class="user-avatar" aria-hidden="true">{{ mb_substr($currentUser?->name ?? 'U',0,1) }}</span><span class="current-user">{{ $currentUser?->name }}</span><a class="account-link" href="{{ route('account.password',['company'=>$tenant->slug]) }}">Minha senha</a><form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button class="text-button">Sair</button></form></div>
    </header>
    <main id="main-content" tabindex="-1">
        @if(isset($supportAdmin))<div class="notice support-banner" role="status"><strong>Suporte Vexo · DBA: {{ $supportAdmin->name }}</strong><p>Loja: {{ $tenant->name }}. Acesso global identificado e auditado.</p><form method="post" action="{{ route('platform.support.leave') }}">@csrf<button class="button secondary">Encerrar suporte e voltar à central</button></form></div>@endif
        @include('components.company-report-header',['reportPrintOnly'=>!request()->routeIs('reports','commissions','sale.show','document.show','purchase.show','cash','cashflow','finance')])
        <div class="page-top"><div><div class="eyebrow">@yield('navigation-section','GESTÃO COMERCIAL')</div><h1>@yield('title','Visão geral')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>
        @if(session('success'))
            <div class="notice success" role="status">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="notice error" id="validation-errors" tabindex="-1" role="alert"><strong>Confira os dados antes de continuar:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            <script id="validation-fields" type="application/json">@json($errors->keys())</script>
        @endif
        @if($currentUser?->role==='admin' && request()->routeIs('dashboard') && !$companySetup?->completed_at)
            <div class="notice" role="status">Confira a implantação desta empresa. <a href="{{ route('setup',['company'=>$tenant->slug]) }}">Configurar operação e acompanhar etapas</a></div>
        @endif
        @yield('content')
    </main>
    <footer class="product-owner">Vendra · um produto <img src="/brand/vexo-logo-dark.png" width="2172" height="724" alt="Vexo"></footer>
</div>
<script src="/ui.js?v={{ filemtime(public_path('ui.js')) }}"></script>
@stack('scripts')
</body>
</html>
