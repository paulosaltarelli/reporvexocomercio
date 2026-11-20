<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title','Central') · Vexo</title>
    <link rel="icon" type="image/png" href="/brand/vexo-symbol-navy.png">
    <link rel="stylesheet" href="/brand.css?v=20261116-vexo1">
    <link rel="stylesheet" href="/platform.css?v={{ filemtime(public_path('platform.css')) }}">
    <link rel="stylesheet" href="/platform-content.css?v={{ filemtime(public_path('platform-content.css')) }}">
    @if(request()->routeIs('platform.license.installments.show'))
        <link rel="stylesheet" href="/license-payment.css?v={{ filemtime(public_path('license-payment.css')) }}">
    @endif
    @isset($platformAdmin)<script src="/platform-shell.js?v={{ filemtime(public_path('platform-shell.js')) }}" defer></script>@endisset
</head>
<body class="{{ isset($platformAdmin) ? 'platform-shell' : 'platform-guest' }}">
<a class="skip-link" href="#platform-content">Ir para o conteúdo</a>
@isset($platformAdmin)
    @php
        $navigation = [
            ['Empresas', 'platform.index', ['platform.index','platform.companies.*','platform.requests.*','platform.company-access.*','platform.license.*'], 'M3 21h18M5 21V5l9-2v18M14 9h5v12M8 8h2m-2 4h2m-2 4h2'],
            ['Financeiro', 'platform.receivables', ['platform.receivables'], 'M3 5h18v14H3zM3 10h18m-5 5h2'],
            ['Configurações', 'platform.settings', ['platform.settings*','platform.admins*'], 'M4 6h8m4 0h4M4 12h2m4 0h10M4 18h10m4 0h2M12 3v6M6 9v6m8 0v6'],
        ];
        $activeLink = collect($navigation)->first(fn ($link) => request()->routeIs(...$link[2]));
    @endphp
    <aside class="platform-rail">
        <div class="rail-head">
            <a class="platform-brand" href="{{ route('platform.index') }}" aria-label="Central Vexo">
                <img class="vexo-central-logo" src="/brand/vexo-logo-light.png" width="2172" height="724" alt="Vexo">
            </a>
            <button id="platform-menu-toggle" class="rail-toggle" type="button" aria-expanded="false" aria-controls="platform-menu" hidden>
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg> Menu
            </button>
        </div>
        <div class="rail-body" id="platform-menu">
            <nav class="rail-group" aria-label="Navegação da central">
                @foreach($navigation as [$label, $route, $patterns, $iconPath])
                    <a href="{{ route($route) }}" @if(request()->routeIs(...$patterns)) aria-current="{{ request()->routeIs($route) ? 'page' : 'location' }}" @endif>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $iconPath }}"/></svg>{{ $label }}
                    </a>
                @endforeach
            </nav>
            <form class="rail-logout" method="post" action="{{ route('platform.logout') }}">@csrf
                <button><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H4v16h5m4-12 4 4-4 4m-5-4h11"/></svg>Sair</button>
            </form>
        </div>
    </aside>
    <div class="platform-workspace">
        <header class="platform-bar">
            <div class="platform-context"><span>Central Vexo</span>
                @if($activeLink)<span aria-hidden="true">/</span><a href="{{ route($activeLink[1]) }}">{{ $activeLink[0] }}</a>@endif
            </div>
            <div class="platform-identity"><span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($platformAdmin->name),0,1)) }}</span><span>{{ $platformAdmin->name }}</span></div>
        </header>
@else
    <header class="guest-brand"><img src="/brand/vexo-logo-dark.png" width="2172" height="724" alt="Vexo"></header>
@endisset
<main class="platform-main" id="platform-content" tabindex="-1">
    <div class="platform-page-heading"><div><h1>@yield('title')</h1>@hasSection('subtitle')<p class="muted">@yield('subtitle')</p>@endif</div>@yield('actions')</div>
    @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="notice error" role="alert"><strong>Confira os dados antes de continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
</main>
@isset($platformAdmin)</div>@endisset
</body>
</html>
