<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <title>@yield('title') · Vendra</title>
    @foreach(['app','brand','interface','company-setup'] as $stylesheet)
        <link rel="stylesheet" href="/{{ $stylesheet }}.css?v={{ filemtime(public_path($stylesheet.'.css')) }}">
    @endforeach
</head>
<body class="onboarding-shell installer-shell">
<a class="skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="workspace">
    <header class="installer-header">
        <div><strong>{{ $tenant->name }}</strong><span class="muted">Implantação</span></div>
        <nav aria-label="Conta de implantação">
            @if(request()->routeIs('account.password'))
                @unless($currentUser->must_change_password)<a href="{{ route(\App\Services\InstallerAccess::open($companySetup ?? null) ? 'setup' : 'installer.home',['company'=>$tenant->slug]) }}">Voltar à implantação</a>@endunless
            @else
                <a href="{{ route('account.password',['company'=>$tenant->slug]) }}">Minha senha</a>
            @endif
            <form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">@csrf<button type="submit" class="text-button">Sair</button></form>
        </nav>
    </header>
    <main id="main-content" tabindex="-1">
        <div class="page-top"><div><h1>@yield('title')</h1><p class="muted">@yield('subtitle')</p></div></div>
        @if(session('success'))<div class="notice success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())
            <div class="notice error" id="validation-errors" tabindex="-1" role="alert"><strong>Confira os campos antes de continuar.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
            <script id="validation-fields" type="application/json">@json($errors->keys())</script>
        @endif
        @yield('content')
    </main>
</div>
<script src="/ui.js?v={{ filemtime(public_path('ui.js')) }}"></script>
@stack('scripts')
</body>
</html>
