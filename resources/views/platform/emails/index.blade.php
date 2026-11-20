@extends('platform.layout')
@section('title','E-mails enviados')
@section('subtitle','Consulte as tentativas de envio registradas desde a implantação do histórico.')
@section('content')
@include('platform.settings-navigation')
<section class="panel central-list">
    <div class="central-list-heading"><h2>Histórico de envios</h2><span class="central-count">{{ $attempts->total() }}</span></div>
    <form class="platform-filters central-toolbar" method="get" action="{{ route('platform.settings.emails.index') }}">
        <div class="field"><label for="mail-recipient">Destinatário</label>
            <input id="mail-recipient" type="search" name="recipient" maxlength="254" placeholder="Endereço de e-mail" value="{{ $filters['recipient'] ?? '' }}">
        </div>
        <div class="field"><label for="mail-status">Situação</label>
            <select id="mail-status" name="status">
                <option value="">Todas</option>
                @foreach($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="button secondary">Filtrar</button>
        @if(array_filter($filters))<a href="{{ route('platform.settings.emails.index') }}">Limpar filtros</a>@endif
    </form>
    <div class="table-scroll" role="region" aria-label="Histórico de envios" tabindex="0">
        <table><thead><tr><th>Data e hora</th><th>Assunto</th><th>Destinatário</th><th>Empresa</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse($attempts as $attempt)
            <tr>
                <td>{{ $attempt->created_at->format('d/m/Y H:i') }}</td>
                <td><a href="{{ route('platform.settings.emails.show', ['id'=>$attempt->id] + request()->only(['recipient','status','page'])) }}">{{ $attempt->subject }}</a></td>
                <td>{{ $attempt->recipient }}</td><td>{{ $attempt->company_name }}</td>
                <td><span class="central-status" data-tone="{{ match($attempt->status){'sent'=>'good','failed'=>'bad',default=>'wait'} }}">{{ $statuses[$attempt->status] ?? 'Desconhecido' }}</span></td>
            </tr>
        @empty
            <tr><td class="empty" colspan="5">{{ array_filter($filters) ? 'Nenhum envio corresponde aos filtros.' : 'Nenhum envio registrado.' }}</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="pagination">{{ $attempts->links('platform.pagination',['label'=>'Páginas de envios']) }}</div>
</section>
@endsection
