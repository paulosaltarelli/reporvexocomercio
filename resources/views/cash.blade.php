@extends('layout')
@section('title','Caixa diário')
@section('subtitle','Confira o dinheiro físico, os movimentos por forma de pagamento e registre o fechamento do dia.')
@section('actions')<button type="button" class="button secondary no-print" onclick="window.print()">Imprimir / salvar PDF</button>@endsection
@section('content')
<div class="context-strip"><span><strong>Dia da conferência:</strong> {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</span><span class="badge {{ $closure?'':'warn' }}">{{ $closure?'Fechamento registrado':'Dia em aberto' }}</span></div>
<p class="report-period">Conferência de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</p>
<form class="panel toolbar filter-bar cash-filters" method="get"><div><label for="cash-date">Dia de conferência</label><input id="cash-date" type="date" name="date" value="{{ $date }}" max="{{ now()->toDateString() }}" required></div><div><label for="opening-filter">Abertura em dinheiro (R$)</label><input id="opening-filter" name="opening_balance" type="number" min="0" step="0.01" value="{{ $summary['opening_balance'] }}" @readonly($last || $closure)><small class="hint">Quando houver fechamento anterior, a abertura é vinculada ao valor contado nele.</small></div><button class="primary">Conferir dia</button></form>
@if($nextDate && $nextDate<$date && !$closure)<div class="notice">Há movimentos anteriores ainda abertos. <a href="{{ route('cash',['company'=>$tenant->slug,'date'=>$nextDate]) }}">Conferir {{ \Carbon\Carbon::parse($nextDate)->format('d/m/Y') }} primeiro</a>.</div>@endif
<div class="cards"><div class="card"><small>Abertura em dinheiro</small><strong>R$ {{ number_format($summary['opening_balance'],2,',','.') }}</strong></div><div class="card"><small>Entradas em dinheiro</small><strong>R$ {{ number_format($summary['cash_in'],2,',','.') }}</strong></div><div class="card"><small>Saídas em dinheiro</small><strong>R$ {{ number_format($summary['cash_out'],2,',','.') }}</strong></div><div class="card featured"><small>Dinheiro esperado ao fechar</small><strong>R$ {{ number_format($summary['expected_balance'],2,',','.') }}</strong></div></div>
<section class="panel"><h2>Movimentos por forma de pagamento</h2><div class="scroll"><table><thead><tr><th>Forma</th><th class="num">Entradas</th><th class="num">Saídas / reembolsos</th><th class="num">Movimento líquido</th></tr></thead><tbody>
@forelse($summary['totals_by_method'] as $method=>$totals)<tr><td>{{ ['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Crédito','debit'=>'Débito','transfer'=>'Transferência','invoice'=>'Outro registro'][$method]??$method }}</td><td class="num">{{ number_format($totals['in'],2,',','.') }}</td><td class="num">{{ number_format($totals['out'],2,',','.') }}</td><td class="num">{{ number_format($totals['net'],2,',','.') }}</td></tr>
@empty<tr><td colspan="4" class="empty">Nenhuma baixa registrada neste dia.</td></tr>
@endforelse</tbody></table></div><p class="hint">Pix, cartões e transferências são conferidos separadamente e não compõem o dinheiro físico da gaveta. As baixas são manuais; não há integração com bancos ou maquininha.</p></section>
@if($closure)<section class="panel"><h2>Fechamento registrado</h2><div class="cards"><div class="card"><small>Contado em dinheiro</small><strong>R$ {{ number_format($closure->counted_balance,2,',','.') }}</strong></div><div class="card"><small>Diferença: contado − esperado</small><strong>R$ {{ number_format($closure->difference,2,',','.') }}</strong></div></div><p>{{ ($demoMode??false) ? match($closure->notes) { 'DEMO: diferença de R$ 10 identificada na contagem.' => 'Diferença de R$ 10 identificada na contagem.', 'DEMO: fechamento fictício para conhecer a rotina.' => 'Conferência diária de caixa.', default => $closure->notes } : $closure->notes }}</p><p class="hint">Fechado em {{ $closure->closed_at->format('d/m/Y H:i') }} · usuário #{{ $closure->user_id }}. Registro imutável; novas baixas para esse dia ou anteriores não são aceitas.</p></section>
@else<section class="panel"><h2>Concluir conferência</h2><form method="post" action="{{ route('cash.close',['company'=>$tenant->slug]) }}" onsubmit="return confirm('Confirmar fechamento? Não será possível alterar o registro nem inserir baixas neste dia ou anteriores.');">@csrf<input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="date" value="{{ $date }}"><input type="hidden" name="opening_balance" value="{{ $summary['opening_balance'] }}"><div class="form-grid"><div class="field"><label>Valor contado em dinheiro (R$)</label><input type="number" name="counted_balance" min="0" step="0.01" value="{{ old('counted_balance') }}" required aria-describedby="cash-count-preview"><small class="hint" id="cash-count-preview" role="status" aria-live="polite">Informe o valor contado para conferir a diferença antes de fechar.</small></div><div class="field"><label>Observações da conferência</label><textarea name="notes" maxlength="1000">{{ old('notes') }}</textarea></div></div><div class="actions"><button class="primary">Fechar caixa de {{ \Carbon\Carbon::parse($date)->format('d/m/Y') }}</button></div><p class="hint">Primeira abertura é informada manualmente. Depois, a abertura deve corresponder ao valor contado no fechamento anterior. Feche os dias com movimentos em ordem; correções devem ser identificadas em um dia ainda aberto.</p></form></section>@endif
<details class="panel disclosure no-print"><summary><span>Histórico de fechamentos</span><small>Conferências anteriores</small></summary><div class="disclosure-content"><div class="scroll"><table><thead><tr><th>Dia</th><th class="num">Abertura</th><th class="num">Esperado</th><th class="num">Contado</th><th class="num">Diferença</th><th></th></tr></thead><tbody>
@forelse($history as $item)<tr><td>{{ $item->date->format('d/m/Y') }}</td><td class="num">{{ number_format($item->opening_balance,2,',','.') }}</td><td class="num">{{ number_format($item->expected_balance,2,',','.') }}</td><td class="num">{{ number_format($item->counted_balance,2,',','.') }}</td><td class="num">{{ number_format($item->difference,2,',','.') }}</td><td><a href="{{ route('cash',['company'=>$tenant->slug,'date'=>$item->date->toDateString()]) }}">Conferir</a></td></tr>
@empty<tr><td colspan="6" class="empty">Nenhum fechamento registrado.</td></tr>
@endforelse</tbody></table></div>{{ $history->links() }}</div></details>
@endsection

@push('scripts')
<script>
(() => {
    const counted=document.querySelector('input[name="counted_balance"]');
    const preview=document.querySelector('#cash-count-preview');
    if(!counted || !preview)return;
    const rawExpected=@json((string)$summary['expected_balance']);
    const expectedAbs=Vendra.decimalUnits(rawExpected.replace(/^-/,'').replace(/^\+/,''),2);
    if(expectedAbs===null)return;
    const expected=rawExpected.startsWith('-')?-expectedAbs:expectedAbs;
    function updateCount(){
        const units=Vendra.decimalUnits(counted.value,2);
        if(units===null){preview.textContent='Informe o valor contado para conferir a diferença antes de fechar.';preview.classList.remove('count-difference');return;}
        const difference=units-expected;
        preview.textContent='Diferença prevista: '+Vendra.centsText(difference)+(difference===0n?' · contado confere com o esperado.':' · revise a contagem e registre a justificativa nas observações.');
        preview.classList.toggle('count-difference',difference!==0n);
    }
    counted.addEventListener('input',updateCount);updateCount();
})();
</script>
@endpush
