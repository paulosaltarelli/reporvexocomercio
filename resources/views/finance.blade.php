@extends('layout')
@section('title','Financeiro')
@section('subtitle','Contas, parcelas e baixas manuais. Registre o que foi pago ou recebido e acompanhe o saldo restante.')
@section('actions')<button type="button" class="button secondary no-print" onclick="window.print()">Imprimir / salvar PDF</button>@endsection
@section('content')
<div class="cards">
    <div class="card"><small>A pagar · saldo pendente</small><strong>R$ {{ number_format($payable,2,',','.') }}</strong></div>
    <div class="card"><small>A receber · saldo pendente</small><strong>R$ {{ number_format($receivable,2,',','.') }}</strong></div>
    <div class="card"><small>Entradas registradas · mês atual</small><strong>R$ {{ number_format($cashIn,2,',','.') }}</strong></div>
    <div class="card"><small>Saídas registradas · mês atual</small><strong>R$ {{ number_format($cashOut,2,',','.') }}</strong></div>
</div>
<p class="hint">Os indicadores mostram a posição geral da empresa. Os filtros abaixo refinam apenas a lista de contas e parcelas.</p>
@if($currentUser->allows('finance.manage'))
<details class="panel disclosure" @if(old('direction') || ($entries->isEmpty() && !request()->hasAny(['q','direction','status','from','to','sale']))) open @endif>
    <summary><span>+ Nova conta manual</span><small>Despesas e outras receitas</small></summary>
    @if($currentUser->allows('finance.manage'))
<form method="post" class="disclosure-content">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('direction')?old('request_key',\Illuminate\Support\Str::uuid()):\Illuminate\Support\Str::uuid() }}">
        <div class="form-grid">
            <div class="field"><label>Tipo</label><select name="direction"><option value="out" @selected(old('direction','out')==='out')>A pagar</option><option value="in" @selected(old('direction')==='in')>A receber</option></select></div>
            <div class="field"><label>Descrição</label><input name="description" maxlength="180" value="{{ old('description') }}" required placeholder="Ex.: aluguel da loja"></div>
            <div class="field"><label>Categoria</label><input name="category" maxlength="100" value="{{ old('category') }}" required placeholder="Ex.: estrutura"></div>
            <div class="field"><label>Valor total (R$)</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ old('direction')?old('amount'):'' }}" required></div>
            <div class="field"><label>Primeiro vencimento</label><input name="due_date" type="date" value="{{ old('due_date') }}" required></div>
            <div class="field"><label>Número de parcelas</label><input name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required><small class="hint">O total será dividido em parcelas mensais, sem juros automáticos.</small></div>
            <div class="field"><label>Fornecedor (opcional)</label><select name="supplier_id"><option value="">Não informado</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(old('supplier_id')==$supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        </div>
        <div class="actions"><button class="primary">Registrar conta</button></div>
        <p class="hint">Compras e vendas geram suas próprias contas. Não cadastre novamente o mesmo compromisso aqui.</p>
    </form>
@endif
</details>
@endif
<section class="panel">
    <div class="section-heading"><h2>Contas e parcelas</h2>@if(request('sale'))<a href="{{ route('finance',['company'=>$tenant->slug]) }}">Limpar filtro da venda #{{ request('sale') }}</a>@endif</div>
    <form class="filter-bar no-print" method="get" aria-label="Filtrar contas e parcelas">
        @if($saleFilter??null)<input type="hidden" name="sale" value="{{ $saleFilter }}">@endif
        <div><label for="finance-search">Buscar conta</label><input id="finance-search" name="q" maxlength="100" value="{{ $search??'' }}" placeholder="Descrição, categoria ou número"></div>
        <div><label for="finance-direction">Tipo</label><select id="finance-direction" name="direction"><option value="">A pagar e a receber</option><option value="out" @selected(($directionFilter??'')==='out')>A pagar</option><option value="in" @selected(($directionFilter??'')==='in')>A receber</option></select></div>
        <div><label for="finance-status">Situação</label><select id="finance-status" name="status"><option value="">Todas</option>@foreach(['pending'=>'Pendentes e parciais','overdue'=>'Vencidas','paid'=>'Liquidadas','cancelled'=>'Canceladas'] as $value=>$label)<option value="{{ $value }}" @selected(($statusFilter??'')===$value)>{{ $label }}</option>@endforeach</select></div>
        <div><label for="finance-from">Vencimento a partir de</label><input id="finance-from" name="from" type="date" value="{{ $from??'' }}"></div>
        <div><label for="finance-to">Vencimento até</label><input id="finance-to" name="to" type="date" value="{{ $to??'' }}"></div>
        <button class="primary">Aplicar filtros</button>
        @if(request()->hasAny(['q','direction','status','from','to','sale']))<a href="{{ route('finance',['company'=>$tenant->slug]) }}">Limpar filtros</a>@endif
    </form>
    <p class="hint report-period">{{ $entries->total() }} contas e parcelas encontradas · página {{ $entries->currentPage() }} de {{ $entries->lastPage() }}. @if($from??null)Vencimento a partir de {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}.@endif @if($to??null)Até {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}.@endif A impressão inclui esta página da listagem.</p>
    <div class="scroll"><table>
        <thead><tr><th>Descrição / origem</th><th>Tipo</th><th>Vencimento</th><th class="num">Valor (R$)</th><th class="num">Baixado (R$)</th><th class="num">Saldo (R$)</th><th>Situação</th><th></th></tr></thead>
        <tbody>
        @forelse($entries as $entry)
            @php($rawBalance = bcsub((string)$entry->amount,(string)($entry->paid_amount??'0'),2))
            @php($balance = bccomp($rawBalance,'0',2)>0 ? $rawBalance : '0.00')
            @php($isPending = !$entry->cancelled_at && bccomp($balance,'0',2)>0)
            @php($editingEntry = (string)old('entry_id')===(string)$entry->id)
            <tr>
                <td class="product-name"><strong>{{ $entry->description }}</strong><small class="hint">{{ $entry->category }}@if(($entry->installments_count??1)>1) · parcela {{ $entry->installment_no }}/{{ $entry->installments_count }}@endif</small>
                    @if($entry->sale_id && $currentUser->allows('sales.view'))<a class="hint" href="{{ route('sale.show',['company'=>$tenant->slug,'id'=>$entry->sale_id]) }}">Venda #{{ $entry->sale_id }}</a>@endif
                    @if($entry->purchase_id && $currentUser->allows('purchases.view'))<a class="hint" href="{{ route('purchase.show',['company'=>$tenant->slug,'id'=>$entry->purchase_id]) }}">Compra #{{ $entry->purchase_id }}</a>@endif
                    @if($entry->supplier)<small class="hint">{{ $entry->supplier->name }}</small>@endif
                </td>
                <td>{{ $entry->direction==='out'?'A pagar':'A receber' }}</td>
                <td>{{ \Carbon\Carbon::parse($entry->due_date)->format('d/m/Y') }}</td>
                <td class="num">{{ number_format($entry->amount,2,',','.') }}</td>
                <td class="num">{{ number_format($entry->paid_amount??0,2,',','.') }}</td>
                <td class="num">{{ $entry->cancelled_at?'—':number_format($balance,2,',','.') }}</td>
                <td><span class="badge {{ $entry->cancelled_at?'bad':($isPending?'warn':'') }}">{{ $entry->cancelled_at?'Cancelada':($isPending?(bccomp((string)($entry->paid_amount??'0'),'0',2)>0?'Parcial':'Pendente'):'Liquidada') }}</span></td>
                <td>@if($isPending && $currentUser->allows('finance.manage'))<a href="#entry-{{ $entry->id }}" class="text-button" data-open-disclosure="entry-{{ $entry->id }}">{{ $entry->direction==='out'?'Registrar pagamento':'Registrar recebimento' }}</a>@endif</td>
            </tr>
            @if($isPending && $currentUser->allows('finance.manage'))
                <tr class="entry-payment-row"><td colspan="8">
                    <details id="entry-{{ $entry->id }}" class="disclosure compact payment-disclosure" @if($editingEntry) open @endif>
                        <summary>Baixa da conta #{{ $entry->id }} · saldo R$ {{ number_format($balance,2,',','.') }}</summary>
                        @if($currentUser->allows('finance.manage'))
<form method="post" action="{{ route('finance.pay',['company'=>$tenant->slug,'id'=>$entry->id]) }}" class="disclosure-content">
                            @csrf
                            <input type="hidden" name="entry_id" value="{{ $entry->id }}">
                            <input type="hidden" name="request_key" value="{{ $editingEntry?old('request_key',\Illuminate\Support\Str::uuid()):\Illuminate\Support\Str::uuid() }}">
                            <div class="form-grid">
                                <div class="field"><label>Valor {{ $entry->direction==='out'?'pago':'recebido' }} agora (R$)</label><input name="amount" type="number" min="0.01" max="{{ number_format($balance,2,'.','') }}" step="0.01" value="{{ $editingEntry?old('amount'):number_format($balance,2,'.','') }}" required><small class="hint">Informe até o saldo. Uma baixa menor mantém o restante pendente.</small></div>
                                <div class="field"><label>Data do {{ $entry->direction==='out'?'pagamento':'recebimento' }}</label><input type="date" name="paid_on" value="{{ $editingEntry?old('paid_on',now()->toDateString()):now()->toDateString() }}" required></div>
                                <div class="field"><label>Forma de pagamento</label>@include('components.payment-method-select',['paymentSelectId'=>'payment-option','paymentDefault'=>'pix'])</div>
                            </div>
                            <div class="actions"><button class="primary">Confirmar baixa registrada</button></div>
                            <p class="hint">Confirme somente valores já movimentados fora do sistema. Esta ação não transfere dinheiro nem cobra o cliente.</p>
                        </form>
@endif
                    </details>
                </td></tr>
            @endif
        @empty
            <tr><td colspan="8" class="empty">Nenhuma conta encontrada. Compras, vendas e contas manuais aparecerão aqui.</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $entries->links() }}
</section>
<details class="panel disclosure">
    <summary><span>Últimas movimentações registradas</span><small>Baixas e estornos</small></summary>
    <div class="disclosure-content">
        <div class="scroll"><table><thead><tr><th>Data informada</th><th>Movimento</th><th>Tipo</th><th>Forma</th><th class="num">Valor (R$)</th></tr></thead><tbody>
        @forelse($payments as $payment)
            <tr><td>{{ $payment->paid_on?\Carbon\Carbon::parse($payment->paid_on)->format('d/m/Y'):'Não informada' }}</td><td>{{ $payment->direction==='in'?'Entrada':'Saída' }}</td><td>{{ $payment->kind==='refund'?'Estorno':'Baixa' }}</td><td>{{ app(\App\Services\PaymentMethods::class)->labels('payment_method_used',[$payment->id])[$payment->id]??\App\Services\PaymentMethods::TYPES[$payment->method]??$payment->method }}</td><td class="num">{{ number_format($payment->amount,2,',','.') }}</td></tr>
        @empty
            <tr><td colspan="5" class="empty">Nenhuma baixa ou estorno registrado.</td></tr>
        @endforelse
        </tbody></table></div>
        <p class="hint">Valores legados já baixados sem data comprovada entram no saldo das contas, mas não recebem uma movimentação retroativa fictícia. Este histórico não substitui a conciliação bancária.</p>
    </div>
</details>
@endsection
