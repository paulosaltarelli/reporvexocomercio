@php
    $points = array_values($trend ?? []);
    $pointCount = count($points);
    $peak = $pointCount ? max(array_map(fn($point)=>(float)$point['total'],$points)) : 0.0;
    $scale = max($peak,0.01);
    $plotWidth = 800;
    $slot = $pointCount ? $plotWidth / $pointCount : $plotWidth;
    $axisLabel = fn($value)=>abs($value)>=1000000?number_format($value/1000000,1,',','.').' mi':(abs($value)>=1000?number_format($value/1000,1,',','.').' mil':number_format($value,2,',','.'));
@endphp
<section class="panel chart-panel" aria-labelledby="sales-trend-heading">
    <div class="section-heading chart-heading">
        <div><h2 id="sales-trend-heading">Ritmo das vendas</h2><p class="hint">Últimos 14 dias · vendas concluídas, sem cancelamentos</p></div>
        @if($periodTotal!==null)<div class="chart-period-total"><strong>R$ {{ number_format($periodTotal,2,',','.') }}</strong>@if($periodCount!==null)<small>{{ $periodCount }} {{ $periodCount===1?'venda concluída':'vendas concluídas' }}</small>@endif</div>@endif
    </div>
    @if($pointCount)
        <figure class="data-chart">
            <div class="chart-legend"><span><i class="legend-marker blue"></i>Valor vendido por dia</span><span class="muted">Valores em R$ · dias sem venda incluídos</span></div>
            <div class="chart-layout">
                <div class="chart-y-axis" aria-hidden="true"><span>@if($peak>0){{ $axisLabel($peak) }}@endif</span><span>@if($peak>0){{ $axisLabel($peak/2) }}@endif</span><span>0</span></div>
                <div class="chart-plot">
                    <svg viewBox="0 0 800 180" preserveAspectRatio="none" role="img" aria-labelledby="sales-svg-title sales-svg-description" class="chart-svg">
                        <title id="sales-svg-title">Total vendido por dia nos últimos 14 dias</title>
                        <desc id="sales-svg-description">Cada barra representa as vendas concluídas do dia. Os valores exatos estão na tabela expansível abaixo. Dias sem valor de venda permanecem em zero.</desc>
                        @foreach([12,90,168] as $gridY)<line x1="0" y1="{{ $gridY }}" x2="800" y2="{{ $gridY }}" class="chart-grid-line" vector-effect="non-scaling-stroke"/>@endforeach
                        @foreach($points as $point)
                            @php($height=max(0,(float)$point['total'])/$scale*156)
                            @php($barWidth=max(2,$slot*.52))
                            @php($x=$loop->index*$slot+($slot-$barWidth)/2)
                            @if($height>0)<rect x="{{ number_format($x,3,'.','') }}" y="{{ number_format(168-$height,3,'.','') }}" width="{{ number_format($barWidth,3,'.','') }}" height="{{ number_format($height,3,'.','') }}" rx="3" class="chart-bar {{ $loop->last?'latest':'' }}"><title>{{ \Carbon\Carbon::parse($point['date'])->format('d/m/Y') }}: R$ {{ number_format($point['total'],2,',','.') }}</title></rect>@endif
                        @endforeach
                    </svg>
                    <div class="chart-x-axis" aria-hidden="true"><span>{{ \Carbon\Carbon::parse($points[0]['date'])->format('d/m') }}</span>@if($pointCount>2)<span>{{ \Carbon\Carbon::parse($points[(int)floor(($pointCount-1)/2)]['date'])->format('d/m') }}</span>@endif<span>{{ \Carbon\Carbon::parse($points[$pointCount-1]['date'])->format('d/m') }}</span></div>
                </div>
            </div>
            @if($peak<=0)<figcaption class="hint">Não há valor de venda para desenhar neste período. Os dias e valores continuam disponíveis abaixo.</figcaption>@endif
        </figure>
        <details class="disclosure compact chart-values" id="sales-trend-values"><summary>Conferir valores por dia</summary><div class="disclosure-content scroll" tabindex="0" role="region" aria-label="Valores diários de vendas"><table><thead><tr><th>Dia</th><th class="num">Vendas concluídas (R$)</th></tr></thead><tbody>@foreach($points as $point)<tr><td>{{ \Carbon\Carbon::parse($point['date'])->format('d/m/Y') }}</td><td class="num">{{ number_format($point['total'],2,',','.') }}</td></tr>@endforeach</tbody></table></div></details>
    @else
        <p class="empty">A série de vendas ainda não está disponível para esta visualização.</p>
    @endif
</section>
