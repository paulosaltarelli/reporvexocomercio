<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title','Visão geral') · Vendra</title><link rel="stylesheet" href="/app.css"></head><body>
<aside><a class="brand" href="{{ route('dashboard',['company'=>$tenant->slug]) }}">vendra<span>por Vexo</span></a><div class="company">{{ $tenant->name }}</div><small>OPERAÇÃO</small>
@foreach(['dashboard'=>'Visão geral','sales'=>'Vendas','documents'=>'Orçamentos e OS','products'=>'Produtos','stock'=>'Estoque'] as $route=>$label)<a class="nav {{ request()->routeIs($route) ? 'selected':'' }}" href="{{ route($route,['company'=>$tenant->slug]) }}">{{ $label }}</a>
@endforeach
@foreach(['groups'=>'Grupos e margens','customers'=>'Clientes','suppliers'=>'Fornecedores','services'=>'Serviços'] as $kind=>$label)<a class="nav {{ request()->route('kind')===$kind ? 'selected':'' }}" href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}">{{ $label }}</a>
@endforeach
@if($currentUser?->role==='admin')<a class="nav" href="{{ route('finance',['company'=>$tenant->slug]) }}">Contas e recebimentos</a><small>ADMINISTRAÇÃO</small><a class="nav" href="{{ route('users',['company'=>$tenant->slug]) }}">Usuários e acesso</a>
@endif
<div class="aside-bottom">Gestão que acontece.<br><span>Vendra · primeira versão</span></div></aside>
<div class="workspace"><header><span>Seu negócio, em movimento</span><div>{{ $currentUser?->name }} <form method="post" action="{{ route('logout',['company'=>$tenant->slug]) }}">
@csrf<button class="text-button">Sair</button></form></div></header><main>
<div class="page-top"><div><div class="eyebrow">VENDRA / GESTÃO COMERCIAL</div><h1>@yield('title','Visão geral')</h1><p class="muted">@yield('subtitle')</p></div>@yield('actions')</div>
@if(session('success'))<div class="notice success">{{ session('success') }}</div>
@endif
@if($errors->any())<div class="notice error"><strong>Confira os dados:</strong><ul>
@foreach($errors->all() as $error)<li>{{ $error }}</li>
@endforeach</ul></div>
@endif
@yield('content')
</main><footer>Um produto Vexo · NFS-e ainda não disponível</footer></div>@stack('scripts')</body></html>
