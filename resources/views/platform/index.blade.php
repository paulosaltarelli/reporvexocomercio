@extends('platform.layout')
@section('title','Empresas')
@section('subtitle','Cadastro das lojas e solicitações de implantação.')
@section('actions')<a class="button" href="{{ route('platform.companies.create') }}">Solicitar implantação</a>@endsection
@section('content')
<section class="panel central-list">
    <div class="central-list-heading"><h2>Empresas cadastradas</h2><span class="central-count">{{ $companies->total() }}</span></div>
    <form class="platform-filters central-toolbar" method="get" action="{{ route('platform.index') }}">
        <div class="field"><label for="companies-search">Buscar empresa</label><input type="search" id="companies-search" name="q" value="{{ request('q') }}" maxlength="100" placeholder="Nome ou identificação de acesso"></div>
        <div class="field"><label for="companies-status">Situação</label><select id="companies-status" name="status"><option value="">Todas</option><option value="active" @selected(request('status')==='active')>Ativas</option><option value="inactive" @selected(request('status')==='inactive')>Inativas</option></select></div>
        <div class="field"><label for="companies-segment">Segmento</label>
            <select id="companies-segment" name="segment"><option value="">Todos os segmentos</option>
                @foreach($segments as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['segment'] ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="button secondary">Filtrar</button>
        @if(request()->filled('q')||request()->filled('status') || request()->filled('segment'))<a href="{{ route('platform.index') }}">Limpar filtros</a>@endif
    </form>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Empresas cadastradas">
        <table class="central-directory"><thead><tr><th>Empresa</th><th>Situação</th><th>Cadastro</th><th>Acesso da loja</th></tr></thead>
        @forelse($companies->getCollection()->groupBy('operation_segment') as $segment => $group)
            <tbody>
                <tr class="company-segment-heading">
                    <th colspan="4" scope="rowgroup">{{ $segments[$segment ?: 'unassigned'] }} <span class="central-count">{{ $segmentCounts->get($segment, $group->count()) }}</span></th>
                </tr>
            @foreach($group as $company)
            <tr>
                <td><a class="central-record" href="{{ route('platform.companies.show',$company->id) }}">{{ $company->name }}</a><small>{{ $company->slug }}</small>
                    @if($company->is_demonstration??false)<span class="badge demonstration">Demonstração</span>@endif</td>
                <td><span class="central-status" data-tone="{{ $company->active?'good':'neutral' }}">{{ $company->active?'Ativa':'Inativa' }}</span></td>
                <td data-caption="Cadastro">{{ $company->created_at?->format('d/m/Y')??'Não informado' }}</td>
                <td>@if($company->active)<a href="{{ \App\Tenancy\CompanyHosts::url($company->slug, 'login') }}" target="_blank" rel="noopener noreferrer" aria-label="Abrir login de {{ $company->name }} em outra aba">Abrir login <span aria-hidden="true">↗</span></a>@else<span class="muted">Acesso suspenso</span>@endif</td>
            </tr>
            @endforeach
            </tbody>
        @empty
            <tbody><tr><td colspan="4" class="empty">{{ request()->filled('q') || request()->filled('status') || request()->filled('segment') ? 'Nenhuma empresa corresponde aos filtros.' : 'Nenhuma empresa cadastrada.' }}</td></tr></tbody>
        @endforelse
        </table>
    </div>
    {{ $companies->links('platform.pagination',['label'=>'Páginas de empresas']) }}
</section>
<section class="panel central-list">
    <div class="central-list-heading"><h2>Solicitações de implantação</h2><span class="central-count">{{ $requests->total() }}</span></div>
    <div class="table-scroll" tabindex="0" role="region" aria-label="Solicitações de implantação">
        <table class="central-directory"><thead><tr><th>Empresa solicitada</th><th>Segmento</th><th>Situação do pedido</th><th>Responsável</th><th>Solicitação</th></tr></thead><tbody>
        @forelse($requests as $job)
            <tr>
                <td><a class="central-record" href="{{ route('platform.requests.show',$job->id) }}">{{ $job->name }}</a><small>{{ $job->slug }}</small>@if($job->is_demonstration)<span class="badge demonstration">Demonstração</span>@endif</td>
                <td data-caption="Segmento">{{ $segments[$job->operation_segment ?? 'unassigned'] }}</td>
                <td><span class="central-status" data-tone="{{ match($job->status){'completed'=>'good','failed'=>'bad',default=>'wait'} }}">{{ ['pending'=>'Na fila','provisioning'=>'Em implantação','completed'=>'Implantação concluída','failed'=>'Falha na implantação'][$job->status]??'Aguardando conferência' }}</span></td>
                <td data-caption="Responsável">{{ $job->admin_name }}</td><td data-caption="Solicitação">{{ $job->created_at->format('d/m/Y H:i') }}</td>
            </tr>
        @empty
            <tr><td colspan="5" class="empty">{{ request()->filled('segment') ? 'Nenhuma solicitação neste segmento.' : 'Nenhuma solicitação registrada.' }}</td></tr>
        @endforelse
        </tbody></table>
    </div>
    {{ $requests->appends(request()->only(['page','q','status','segment']))->links('platform.pagination',['label'=>'Páginas de solicitações']) }}
</section>
@endsection
