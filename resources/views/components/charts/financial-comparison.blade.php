@php
    $movementPeak=max((float)$cashIn,(float)$cashOut,0);
    $incomingWidth=$movementPeak>0?max(0,(float)$cashIn)/$movementPeak*520:0;
    $outgoingWidth=$movementPeak>0?max(0,(float)$cashOut)/$movementPeak*520:0;
@endphp
<figure style="margin:1rem 0" aria-labelledby="financial-comparison-caption">
    <svg viewBox="0 0 700 132" role="img" aria-labelledby="financial-comparison-title financial-comparison-desc" style="width:100%;height:auto;max-height:160px">
        <title id="financial-comparison-title">Entradas e saídas financeiras no período selecionado</title>
        <desc id="financial-comparison-desc">Entradas: R$ {{ number_format($cashIn,2,',','.') }}. Saídas e reembolsos: R$ {{ number_format($cashOut,2,',','.') }}. As duas barras usam a mesma escala e representam baixas, não vendas.</desc>
        <text x="0" y="35" fill="currentColor" font-size="14">Entradas</text><rect x="160" y="16" width="520" height="26" rx="7" fill="#eef3f9"/>
        @if($incomingWidth>0)<rect x="160" y="16" width="{{ number_format($incomingWidth,3,'.','') }}" height="26" rx="7" fill="#1678da"/>@endif
        <text x="0" y="91" fill="currentColor" font-size="14">Saídas / reembolsos</text><rect x="160" y="72" width="520" height="26" rx="7" fill="#eef3f9"/>
        @if($outgoingWidth>0)<rect x="160" y="72" width="{{ number_format($outgoingWidth,3,'.','') }}" height="26" rx="7" fill="#697b96"/>@endif
    </svg>
    <figcaption id="financial-comparison-caption" class="hint">Mesma escala para entradas e saídas · {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}. @if($movementPeak<=0)Nenhuma baixa registrada neste período.@endif</figcaption>
</figure>
