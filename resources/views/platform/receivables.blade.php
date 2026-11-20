@extends('platform.layout')
@section('title','Financeiro')
@section('subtitle','Acompanhe as parcelas dos contratos das lojas.')
@section('content')
<section class="panel" aria-label="Filtros de parcelas">
    <form method="get" class="platform-filters">
        <div class="field">
            <label for="company-query">Empresa</label>
            <input id="company-query" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Nome ou identificação">
        </div>
        <div class="field">
            <label for="due-month">Mês de vencimento</label>
            <input type="month" id="due-month" name="month" value="{{ $filters['month'] ?? '' }}">
        </div>
        <div class="field">
            <label for="payment-status">Situação da parcela</label>
            <select id="payment-status" name="status">
                <option value="">Todas</option>
                @foreach($labels as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['status'] ?? '') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="button secondary">Filtrar</button>
        @if(array_filter($filters))<a href="{{ route('platform.receivables') }}">Limpar filtros</a>@endif
    </form>
    <dl class="detail-grid">
        @foreach(['upcoming'=>'A vencer','overdue'=>'Em atraso','paid'=>'Recebido'] as $key => $caption)
            <div><dt>{{ $caption }}</dt><dd>R$ {{ number_format($totals[$key] ?? 0, 2, ',', '.') }}</dd></div>
        @endforeach
    </dl>
</section>
<section class="panel">
    <div class="section-heading"><h2>Parcelas</h2><span>{{ $installments->total() }} {{ $installments->total() === 1 ? 'parcela' : 'parcelas' }}</span></div>
    <div class="table-scroll" role="region" aria-label="Parcelas por empresa" tabindex="0">
        <table><thead><tr><th>Empresa</th><th>Contrato</th><th>Vencimento</th><th>Valor</th><th>Situação</th></tr></thead>
        <tbody>
        @forelse($installments as $row)
            <tr>
                <td><a href="{{ route('platform.license.index',['id'=>$row->tenant_id]) }}">{{ $row->company_name }}</a></td>
                <td>#{{ $row->contract_id }}</td>
                <td>{{ \Illuminate\Support\Carbon::parse($row->due_date)->format('d/m/Y') }}</td>
                <td>R$ {{ number_format($row->amount, 2, ',', '.') }}</td>
                <td>{{ $labels[$row->payment_status] }}</td>
            </tr>
        @empty
            <tr><td class="empty" colspan="5">Nenhuma parcela encontrada.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="pagination">{{ $installments->links() }}</div>
</section>
@endsection
