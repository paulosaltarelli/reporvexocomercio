@php
    $isAdmin = $currentUser?->role === 'admin';
    $certificateInMenu = $nfceAvailable && $currentUser->canEnrollFiscalCertificate();
    $link = fn ($route, $label, $active, $parameters = [], $fragment = '') => [
        'label' => $label,
        'href' => route($route, ['company' => $tenant->slug] + $parameters).$fragment,
        'active' => $active,
        'permission' => \App\Access\Permissions::named($route,$parameters),
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
            ...(app(\App\Services\OperationalModes::class)->enabled('restaurant') ? [$link('restaurant.catalog.index','Cardápio e fichas técnicas',request()->routeIs('restaurant.catalog.*')),$link('delivery.index','Integrações de pedidos',request()->routeIs('delivery.*'))] : []),
            ...(app(\App\Services\OperationalModes::class)->enabled('supermarket') ? [$link('supermarket.approvals.index','Autorizações de caixa',request()->routeIs('supermarket.approvals.*'))] : []),
        ]],
        ['label' => 'Cadastros', 'icon' => 'users', 'links' => array_filter([
            $link('products', $catalogLabel, request()->routeIs('products', 'product.*')),
            $link('catalog', 'Clientes', request()->routeIs('catalog') && request()->route('kind') === 'customers', ['kind' => 'customers']),
            $link('catalog', 'Fornecedores', request()->routeIs('catalog') && request()->route('kind') === 'suppliers', ['kind' => 'suppliers']),
            $link('catalog', $isAdmin ? 'Grupos e margens' : 'Grupos', request()->routeIs('catalog') && request()->route('kind') === 'groups', ['kind' => 'groups']),
            $currentUser->allows('sellers.manage') ? $link('sellers', 'Vendedores', request()->routeIs('sellers')) : null,
        ])],
        ['label' => $operationConfig['inventory'] ? 'Estoque e compras' : 'Compras e preços', 'icon' => 'box', 'links' => array_filter([
            $operationConfig['inventory'] ? $link('stock', 'Movimentações', request()->routeIs('stock')) : null,
            $operationConfig['inventory'] ? $link('stock.replenishment', 'Reposição de estoque', request()->routeIs('stock.replenishment')) : null,
            $currentUser->allows('purchases.view') ? $link('purchases', 'Compras', request()->routeIs('purchases', 'purchase.*')) : null,
            $currentUser->allows('prices.view') ? $link('prices', 'Preços e alertas', request()->routeIs('prices', 'price.*')) : null,
        ])],
    ];
    {
        $groups = array_merge($groups, [
            ['label' => 'Financeiro', 'icon' => 'wallet', 'links' => [
                $link('finance', 'Contas a pagar e receber', request()->routeIs('finance', 'finance.*')),
                $link('cashflow', 'Fluxo de caixa', request()->routeIs('cashflow')),
                $link('cash', 'Caixa diário', request()->routeIs('cash', 'cash.*')),
            ]],
            ['label' => 'Fiscal', 'icon' => 'document', 'links' => array_filter([
                $nfceAvailable ? $link('fiscal', 'Documentos fiscais', request()->routeIs('fiscal', 'nfe.*', 'fiscal.document', 'fiscal.sale', 'fiscal.preparation', 'fiscal.product') || (!$certificateInMenu && request()->routeIs('fiscal.company'))) : null,
                $certificateInMenu ? $link('fiscal.company', 'Certificado digital', request()->routeIs('fiscal.company'), [], '#certificado-digital-a1') : null,
                $link('nfse.index', 'Arquivo de serviços', request()->routeIs('nfse.*')),
                $nfceAvailable ? $link('fiscal.profiles.index', 'Perfis tributários', request()->routeIs('fiscal.profiles.*')) : null,
                $allProductInvoices ? $link('fiscal.simulations.index', 'Simulações internas', request()->routeIs('fiscal.simulations.*')) : null,
            ])],
            ['label' => 'Relatórios', 'icon' => 'chart', 'links' => array_filter([
                $link('reports', 'Relatórios gerenciais', request()->routeIs('reports', 'report.*')),
                $link('commissions', 'Comissões da equipe', request()->routeIs('commissions','commission.*')),
                $currentUser->allows('commissions.self') ? $link('commissions.mine','Minhas comissões',request()->routeIs('commissions.mine*')) : null,
            ])],
            ['label' => 'Configurações', 'icon' => 'settings', 'links' => [
                $link('license.index', 'Minha licença', request()->routeIs('license.index', 'license.installments.*')),
                $link('business', 'Minha empresa', request()->routeIs('business')),
                $link('setup', 'Operação e implantação', request()->routeIs('setup*') && request()->integer('step') !== 2),
                $link('setup', 'Parâmetros de estoque', request()->routeIs('setup') && request()->integer('step') === 2, ['step' => 2]),
                $link('users', 'Usuários e permissões', request()->routeIs('users', 'user.*')),
                $link('payment.methods','Formas de pagamento',request()->routeIs('payment.methods*')),
                $link('access.profiles', 'Perfis e permissões', request()->routeIs('access.profiles*')),
                $link('devices.index','Dispositivos e impressão',request()->routeIs('devices.*')),
            ]],
        ]);
    }
    foreach($groups as &$group) $group['links']=array_values(array_filter($group['links'],fn($item)=>$currentUser->allows($item['permission'])));
    unset($group);
    $navigationSection = request()->routeIs('account.password*') ? 'Minha conta' : 'Início';
    foreach ($groups as $group) {
        if (collect($group['links'])->contains('active', true)) $navigationSection = $group['label'];
    }
@endphp
@section('navigation-section', $navigationSection)
<aside class="app-sidebar" aria-label="Navegação da empresa">
    <div class="sidebar-heading">
        @if(($companyProfile ?? null)?->logo_path)
            <a class="tenant-brand" href="{{ route($isAdmin ? 'dashboard' : app(\App\Access\AccessProfiles::class)->landing($currentUser), ['company' => $tenant->slug]) }}" aria-label="{{ $tenant->name }}, início">
                @include('components.tenant-brand-mark')
            </a>
        @else
        <a class="brand" href="{{ route($isAdmin ? 'dashboard' : app(\App\Access\AccessProfiles::class)->landing($currentUser), ['company' => $tenant->slug]) }}" aria-label="Vendra por Vexo, início">vendra<span class="brand-endorsement">por <img src="/brand/vexo-logo-light.png" width="2172" height="724" alt="Vexo"></span></a>
        @endif
        <button type="button" class="sidebar-close" aria-label="Fechar menu"><x-navigation-icon name="close"/></button>
    </div>
    <div class="company"><span class="company-symbol"><x-navigation-icon name="building"/></span><div><span class="company-caption">SUA EMPRESA</span><strong>{{ $tenant->name }}</strong></div></div>
    <div id="company-navigation" class="sidebar-menu">
        <nav aria-label="Menu da empresa" class="grouped-navigation">
            <span class="navigation-caption">PRINCIPAL</span>
            @if($currentUser->allows('dashboard.view'))
                <a class="nav nav-primary {{ request()->routeIs('dashboard') ? 'selected' : '' }}" href="{{ route('dashboard', ['company' => $tenant->slug]) }}" @if(request()->routeIs('dashboard')) aria-current="page" @endif><x-navigation-icon name="grid"/><span>Visão geral</span></a>
            @endif
            @if($currentUser->allows('desk.use') && app(\App\Services\OperationalModes::class)->enabled('commerce'))<a class="nav nav-primary {{ request()->routeIs('desk') ? 'selected' : '' }}" href="{{ route('desk', ['company' => $tenant->slug]) }}" @if(request()->routeIs('desk')) aria-current="page" @endif><x-navigation-icon name="desk"/><span>Frente de caixa</span></a>@endif
            @foreach(['restaurant'=>'Mesas e comandas', 'supermarket'=>'Caixa de supermercado'] as $operationMode=>$operationLabel)
                @if(app(\App\Services\OperationalModes::class)->enabled($operationMode) && $currentUser->allows(\App\Services\OperationalModes::PERMISSIONS[$operationMode]))
                    <a class="nav nav-primary {{ request()->routeIs($operationMode.'.*') ? 'selected' : '' }}" href="{{ route(\App\Services\OperationalModes::ROUTES[$operationMode], ['company'=>$tenant->slug]) }}" @if(request()->routeIs($operationMode.'.*')) aria-current="page" @endif><x-navigation-icon name="desk"/><span>{{ $operationLabel }}</span></a>
                @endif
            @endforeach
            @if(app(\App\Services\OperationalModes::class)->enabled('restaurant') && $currentUser->allows('restaurant.kitchen'))
                <a class="nav nav-primary {{ request()->routeIs('restaurant.production') ? 'selected' : '' }}" href="{{ route('restaurant.production',['company'=>$tenant->slug]) }}" @if(request()->routeIs('restaurant.production')) aria-current="page" @endif><x-navigation-icon name="desk"/><span>Cozinha</span></a>
            @endif
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
    <div class="aside-bottom"><span class="sidebar-product-dot" aria-hidden="true"></span><div class="sidebar-user"><strong>{{ $currentUser?->name }}</strong><small class="sidebar-profile-label">Perfil: {{ app(\App\Access\AccessProfiles::class)->profileName($currentUser) }}</small><a href="{{ route('account.password', ['company' => $tenant->slug]) }}" @if(request()->routeIs('account.password*')) aria-current="page" @endif>Alterar minha senha</a></div></div>
</aside>
<button type="button" class="sidebar-backdrop" aria-label="Fechar menu" hidden></button>
