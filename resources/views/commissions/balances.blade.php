<div class="cards three commission-balances">
<div class="card"><small>Comissões fechadas · acumulado</small><strong>R$ {{ number_format($settlementTotals['closed'],2,',','.') }}</strong></div>
<div class="card"><small>Pagas ao vendedor · acumulado</small><strong>R$ {{ number_format($settlementTotals['paid'],2,',','.') }}</strong></div>
<div class="card featured"><small>Saldo dos fechamentos a pagar</small><strong>R$ {{ number_format($settlementTotals['balance'],2,',','.') }}</strong></div>
</div>
