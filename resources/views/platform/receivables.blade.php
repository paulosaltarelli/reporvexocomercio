@extends('platform.layout')
@section('title','Financeiro')
@section('subtitle','Acompanhe as parcelas dos contratos das lojas.')
@section('content')
<dl class="central-metrics" aria-label="Totais das parcelas selecionadas">
    @foreach(['upcoming'=>'Saldo a vencer','overdue'=>'Saldo em atraso','paid'=>'Recebido nas parcelas selecionadas'] as $key=>$caption)
        <div data-metric="{{ $key }}"><dt>{{ $caption }}</dt><dd>R$ {{ number_format($totals[$key] ?? 0,2,',','.') }}</dd></div>
    @endforeach
</dl>
<section class="panel central-list" aria-label="Parcelas">
    <div class="central-list-heading"><h2>Parcelas</h2><span class="central-count">{{ $installments->total() }}</span></div>
    <form method="get" class="platform-filters central-toolbar">
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
    <div class="table-scroll" role="region" aria-label="Parcelas por empresa" tabindex="0">
        <table class="central-ledger"><thead><tr><th>Empresa / contrato</th><th>Vencimento</th><th>Situação</th><th>Valor</th><th>Recebido</th><th>Saldo a receber</th></tr></thead>
        <tbody>
        @forelse($installments as $row)
            <tr>
                <td><a class="central-record" aria-label="Ver esta parcela de {{ $row->company_name }}" href="{{ route('platform.license.installments.show',['id'=>$row->tenant_id,'installment'=>$row->id]) }}">{{ $row->company_name }}</a><small><a href="{{ route('platform.license.index',['id'=>$row->tenant_id]) }}">Contrato #{{ $row->contract_id }}</a></small></td>
                <td>{{ \Illuminate\Support\Carbon::parse($row->due_date)->format('d/m/Y') }}</td>
                <td><span class="central-status" data-tone="{{ match($row->payment_status){'paid'=>'good','overdue'=>'bad','partial'=>'wait',default=>'neutral'} }}">{{ $labels[$row->payment_status] }}</span></td>
                <td>R$ {{ number_format($row->amount, 2, ',', '.') }}</td>
                <td>R$ {{ number_format($row->paid_amount, 2, ',', '.') }}</td>
                <td>R$ {{ number_format($row->payment_status === 'cancelled' ? 0 : bcsub($row->amount,$row->paid_amount,2), 2, ',', '.') }}</td>
            </tr>
        @empty
            <tr><td class="empty" colspan="6">Nenhuma parcela encontrada.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <div class="pagination">{{ $installments->links('platform.pagination',['label'=>'Páginas de parcelas']) }}</div>
</section>
@endsection
