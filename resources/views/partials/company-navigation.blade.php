@php
    $isAdmin = $currentUser?->role === 'admin';
    $link = fn ($route, $label, $active, $parameters = []) => [
        'label' => $label,
        'href' => route($route, ['company' => $tenant->slug] + $parameters),
        'active' => $active,
    ];
    $catalogLabel = match ($operationConfig['catalog_mode']) {
        'services' => 'Serviços', 'products' => 'Produtos', default => 'Produtos e serviços',
    };
    $documentLabel = $operationConfig['quotes'] && !$operationConfig['work_orders'] ? 'Orçamentos' :
        (!$operationConfig['quotes'] && $operationConfig['work_orders'] ? 'Ordens de serviço' : 'Orçamentos e OS');
    $groups = [
        ['label' => 'Atendimento', 'icon' => 'sale', 'links' => [
            $link('sales', 'Vendas', request()->routeIs('sales', 'sale.*')),
            $link('documents', $documentLabel, request()->routeIs('documents', 'document.*')),
        ]],
        ['label' => 'Cadastros', 'icon' => 'users', 'links' => array_filter([
            $link('products', $catalogLabel, request()->routeIs('products', 'product.*')),
            $link('catalog', 'Clientes', request()->routeIs('catalog') && request()->route('kind') === 'customers', ['kind' => 'customers']),
            $link('catalog', 'Fornecedores', request()->routeIs('catalog') && request()->route('kind') === 'suppliers', ['kind' => 'suppliers']),
            $link('catalog', $isAdmin ? 'Grupos e margens' : 'Grupos', request()->routeIs('catalog') && request()->route('kind') === 'groups', ['kind' => 'groups']),
            $isAdmin ? $link('sellers', 'Vendedores', request()->routeIs('sellers')) : null,
        ])],
        ['label' => $operationConfig['inventory'] ? 'Estoque e compras' : 'Compras e preços', 'icon' => 'box', 'links' => array_filter([
            $operationConfig['inventory'] ? $link('stock', 'Movimentações', request()->routeIs('stock')) : null,
            $isAdmin ? $link('purchases', 'Compras', request()->routeIs('purchases', 'purchase.*')) : null,
            $isAdmin ? $link('prices', 'Preços e alertas', request()->routeIs('prices', 'price.*')) : null,
        ])],
    ];
    if ($isAdmin) {
        $groups = array_merge($groups, [
            ['label' => 'Financeiro', 'icon' => 'wallet', 'links' => [
                $link('finance', 'Contas a pagar e receber', request()->routeIs('finance', 'finance.*')),
                $link('cashflow', 'Fluxo de caixa', request()->routeIs('cashflow')),
                $link('cash', 'Caixa diário', request()->routeIs('cash', 'cash.*')),
            ]],
            ['label' => 'Fiscal', 'icon' => 'document', 'links' => [
                $link('fiscal', 'Documentos fiscais', request()->routeIs('fiscal', 'nfe.*', 'fiscal.document', 'fiscal.sale', 'fiscal.preparation', 'fiscal.product')),
                $link('nfse.index', 'Notas de serviço', request()->routeIs('nfse.*')),
                $link('fiscal.profiles.index', 'Perfis tributários', request()->routeIs('fiscal.profiles.*')),
                $link('fiscal.simulations.index', 'Simulações internas', request()->routeIs('fiscal.simulations.*')),
            ]],
            ['label' => 'Relatórios', 'icon' => 'chart', 'links' => array_filter([
                $link('reports', 'Relatórios gerenciais', request()->routeIs('reports', 'report.*')),
                $operationConfig['commissions'] ? $link('commissions', 'Comissões', request()->routeIs('commissions')) : null,
            ])],
            ['label' => 'Configurações', 'icon' => 'settings', 'links' => [
                $link('business', 'Minha empresa', request()->routeIs('business')),
                $link('setup', 'Operação e implantação', request()->routeIs('setup*')),
                $link('users', 'Usuários e permissões', request()->routeIs('users', 'user.*')),
            ]],
        ]);
    }
    $navigationSection = request()->routeIs('account.password*') ? 'Minha conta' : 'Início';
    foreach ($groups as $group) {
        if (collect($group['links'])->contains('active', true)) $navigationSection = $group['label'];
    }
@endphp
@section('navigation-section', $navigationSection)
<aside class="app-sidebar" aria-label="Navegação da empresa">
    <div class="sidebar-heading">
        <a class="brand" href="{{ route($isAdmin ? 'dashboard' : 'desk', ['company' => $tenant->slug]) }}" aria-label="Vendra por Vexo, início">vendra<span class="brand-endorsement">por <img src="/brand/vexo-logo-light.png" width="2172" height="724" alt="Vexo"></span></a>
        <button type="button" class="sidebar-close" aria-label="Fechar menu"><x-navigation-icon name="close"/></button>
    </div>
    <div class="company"><span class="company-symbol"><x-navigation-icon name="building"/></span><div><span class="company-caption">SUA EMPRESA</span><strong>{{ $tenant->name }}</strong></div></div>
    <div id="company-navigation" class="sidebar-menu">
        <nav aria-label="Menu da empresa" class="grouped-navigation">
            <span class="navigation-caption">PRINCIPAL</span>
            @if($isAdmin)
                <a class="nav nav-primary {{ request()->routeIs('dashboard') ? 'selected' : '' }}" href="{{ route('dashboard', ['company' => $tenant->slug]) }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-navigation-icon name="grid"/><span>Visão geral</span></a>
            @endif
            <a class="nav nav-primary {{ request()->routeIs('desk') ? 'selected' : '' }}" href="{{ route('desk', ['company' => $tenant->slug]) }}" @if(request()->routeIs('desk')) aria-current="page" @endif><x-navigation-icon name="desk"/><span>Frente de caixa</span></a>
            <span class="navigation-caption navigation-section-caption">GESTÃO</span>
            @foreach($groups as $group)
                @if(count($group['links']))
                    @php($groupActive = collect($group['links'])->contains('active', true))
                    <details class="nav-group {{ $groupActive ? 'current-group' : '' }}" @if($groupActive) open @endif>
                        <summary><x-navigation-icon :name="$group['icon']"/><span>{{ $group['label'] }}</span><x-navigation-icon name="chevron" class="navigation-chevron"/></summary>
                        <div class="nav-group-links">
                            @foreach($group['links'] as $item)
                                <a class="nav {{ $item['active'] ? 'selected' : '' }}" href="{{ $item['href'] }}" @if($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    </details>
                @endif
            @endforeach
        </nav>
    </div>
    <div class="aside-bottom"><span class="sidebar-product-dot" aria-hidden="true"></span><div class="sidebar-user"><strong>{{ $currentUser?->name }}</strong><a href="{{ route('account.password', ['company' => $tenant->slug]) }}" @if(request()->routeIs('account.password*')) aria-current="page" @endif>Alterar minha senha</a></div></div>
</aside>
<button type="button" class="sidebar-backdrop" aria-label="Fechar menu" hidden></button>
