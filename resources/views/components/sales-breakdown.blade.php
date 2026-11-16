<div class="insights-grid">
@foreach(['methods'=>'Vendas por forma de pagamento','origins'=>'Vendas por origem'] as $kind=>$title)
<section class="panel insight-panel"><div class="section-heading"><div><h2>{{ $title }}</h2><p class="hint">Vendas concluídas em {{ $salesBreakdown['year'] }} · cancelamentos excluídos</p></div></div><div class="scroll"><table><thead><tr><th>{{ $kind==='methods'?'Forma de pagamento':'Origem' }}</th><th class="num">Total (R$)</th>@if($kind==='origins')<th class="num">Ticket médio</th>@endif<th class="num">%</th></tr></thead><tbody>
@forelse($salesBreakdown[$kind] as $row)
<tr><td><strong>{{ $row['label'] }}</strong>@if($kind==='methods')<small class="hint">{{ \App\Services\PaymentMethods::TYPES[$row['type']]??$row['type'] }}</small>@endif<div class="insight-bar" aria-hidden="true"><span style="width:{{ $row['percent'] }}%"></span></div></td><td class="num">{{ number_format($row['total'],2,',','.') }}</td>@if($kind==='origins')<td class="num">{{ number_format($row['ticket'],2,',','.') }}</td>@endif<td class="num">{{ number_format($row['percent'],1,',','.') }}%</td></tr>
@empty<tr><td colspan="4" class="empty">Nenhuma venda concluída no ano selecionado.</td></tr>@endforelse
</tbody><tfoot><tr><th>Total geral</th><th class="num">{{ number_format($salesBreakdown['total'],2,',','.') }}</th>@if($kind==='origins')<th></th>@endif<th class="num">{{ bccomp($salesBreakdown['total'],'0',2)>0?'100%':'0%' }}</th></tr></tfoot></table></div>
@if($kind==='methods')<p class="hint">Forma escolhida na venda. Valores a prazo podem ainda não ter sido recebidos; não representam o saldo do caixa.</p>@endif
</section>
@endforeach
</div>
