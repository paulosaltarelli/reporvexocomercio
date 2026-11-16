<div class="cards commission-balances">
<div class="card"><small>Comissão apurada nas vendas do período</small><strong>R$ {{ number_format($totals['commission'],2,',','.') }}</strong><small>Regra registrada em cada venda</small></div>
<div class="card"><small>Elegível pelo regime · vendas do período</small><strong>R$ {{ number_format($totals['eligible'],2,',','.') }}</strong><small>Inclui valores já fechados. Aguardando recebimento: R$ {{ number_format($totals['awaiting'],2,',','.') }}</small></div>
<div class="card"><small>Comissões pagas a mim · acumulado</small><strong>R$ {{ number_format($settlementTotals['paid'],2,',','.') }}</strong><small>Fechado até hoje: R$ {{ number_format($settlementTotals['closed'],2,',','.') }}</small></div>
<div class="card featured"><small>Saldo dos meus fechamentos a pagar</small><strong>R$ {{ number_format($settlementTotals['balance'],2,',','.') }}</strong><small>Pagamentos registrados no financeiro</small></div>
</div>
