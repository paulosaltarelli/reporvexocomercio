<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · Vendra</title>
    @foreach(['app','brand','company-setup','password-visibility'] as $sheet)
        <link rel="stylesheet" href="/{{ $sheet }}.css?v={{ filemtime(public_path($sheet.'.css')) }}">
    @endforeach
</head>
<body>
<main class="panel installer-invitation">
    <img src="/brand/vendra-logo.svg" width="122" height="44" alt="Vendra">
    <h1>@yield('title')</h1>
    <p>{{ $tenant->name }}</p>
    @if(session('success'))<p class="notice success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())<div class="notice error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @yield('content')
    <p><a href="{{ route('login',['company'=>$tenant->slug]) }}">Voltar ao login da loja</a></p>
</main>
@stack('scripts')
</body>
</html>
