@php
    $points=array_values($trend??[]);
    $pointCount=count($points);
    $selectedYear=(int)($salesYear??($pointCount?\Carbon\Carbon::parse($points[0]['date'])->year:now()->year));
    $yearOptions=$salesYears??[$selectedYear];
    $peakPoint=null;
    foreach($points as $point){if($peakPoint===null||bccomp((string)$point['total'],(string)$peakPoint['total'],2)>0)$peakPoint=$point;}
    $peak=$peakPoint?(float)$peakPoint['total']:0.0;
    $step=$peak>0?pow(10,floor(log10($peak)))/2:1.0;
    $scale=$peak>0?ceil($peak/$step)*$step:1.0;
    $slot=$pointCount?800/$pointCount:800;
    $barWidth=min(34,max(8,$slot*.5));
    $axisLabel=fn($value)=>abs($value)>=1000000000000?number_format($value/1000000000000,1,',','.').' tri':(abs($value)>=1000000000?number_format($value/1000000000,1,',','.').' bi':(abs($value)>=1000000?number_format($value/1000000,1,',','.').' mi':(abs($value)>=1000?number_format($value/1000,1,',','.').' mil':number_format($value,2,',','.'))));
@endphp
<section class="panel chart-panel annual-sales-chart" aria-labelledby="sales-trend-heading">
    <div class="annual-chart-heading">
        <div><span class="annual-chart-eyebrow">SEU NEGÓCIO AO LONGO DO ANO</span><h2 id="sales-trend-heading">Evolução das vendas</h2><p class="hint">Janeiro a dezembro de {{ $selectedYear }} · vendas concluídas</p></div>
        <form class="annual-year-filter no-print" method="get" action="{{ route('dashboard',['company'=>$tenant->slug]) }}"><div><label for="sales-year">Ano</label><select id="sales-year" name="year">@foreach($yearOptions as $year)<option value="{{ $year }}" @selected((int)$year===$selectedYear)>{{ $year }}</option>@endforeach</select></div><button class="button secondary" type="submit" aria-label="Exibir vendas do ano selecionado">Ver ano</button></form>
    </div>
    <div class="annual-chart-summary">
        <div class="annual-main-total"><span>Total vendido em {{ $selectedYear }}</span><strong>@if($periodTotal!==null)R$ {{ number_format($periodTotal,2,',','.') }}@else
—@endif</strong></div>
        <div><span>Vendas concluídas</span><strong>{{ $periodCount!==null?number_format($periodCount,0,',','.'):'—' }}</strong><small>Cancelamentos excluídos</small></div>
        <div><span>Maior mês em valor vendido</span><strong>{{ $peak>0?($peakPoint['label']??\Carbon\Carbon::parse($peakPoint['date'])->format('m')):'—' }}</strong><small>@if($peak>0)R$ {{ number_format($peakPoint['total'],2,',','.') }}@else
Sem valor vendido no período
@endif</small></div>
    </div>
    @if($pointCount)
        <figure class="data-chart annual-chart-figure">
            <div class="annual-chart-legend"><span><i aria-hidden="true"></i>Valor líquido vendido por mês</span><span>Valores em R$</span></div>
            <div class="chart-layout annual-chart-layout">
                <div class="chart-y-axis annual-y-axis" aria-hidden="true"><span>@if($peak>0){{ $axisLabel($scale) }}@endif</span><span>@if($peak>0){{ $axisLabel($scale/2) }}@endif</span><span>0</span></div>
                <div class="chart-plot">
                    <svg viewBox="0 0 800 240" preserveAspectRatio="none" role="img" aria-labelledby="sales-svg-title sales-svg-description" class="chart-svg annual-chart-svg">
                        <title id="sales-svg-title">Vendas mensais de janeiro a dezembro de {{ $selectedYear }}</title>
                        <desc id="sales-svg-description">Doze meses de vendas concluídas. Cada barra usa o valor real informado pelo sistema; meses sem vendas ficam em zero. Valores e quantidades exatos estão disponíveis na tabela expansível abaixo. Vendas não representam recebimentos de caixa.</desc>
                        <defs><linearGradient id="annual-bar-gradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#2486f5"/><stop offset="100%" stop-color="#1463eb"/></linearGradient><linearGradient id="annual-peak-gradient" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#25c4ed"/><stop offset="100%" stop-color="#168fdb"/></linearGradient></defs>
                        @foreach([20,120,220] as $gridY)<line x1="0" y1="{{ $gridY }}" x2="800" y2="{{ $gridY }}" class="annual-chart-grid" vector-effect="non-scaling-stroke"/>@endforeach
                        @foreach($points as $point)
                            @php
                                $height=max(0,(float)$point['total'])/$scale*200;
                                $x=$loop->index*$slot+($slot-$barWidth)/2;
                                $isPeak=$peak>0&&bccomp((string)$point['total'],(string)$peakPoint['total'],2)===0;
                            @endphp
                            <g class="annual-month-group">
                                @if($height>0)<rect x="{{ number_format($x,3,'.','') }}" y="{{ number_format(220-$height,3,'.','') }}" width="{{ number_format($barWidth,3,'.','') }}" height="{{ number_format($height,3,'.','') }}" rx="6" fill="{{ $isPeak?'url(#annual-peak-gradient)':'url(#annual-bar-gradient)' }}" class="annual-month-bar"><title>{{ $point['label']??\Carbon\Carbon::parse($point['date'])->format('m') }}/{{ $selectedYear }}: R$ {{ number_format($point['total'],2,',','.') }} · {{ $point['count']??0 }} vendas concluídas</title></rect>
                                @else<line x1="{{ number_format($x+($barWidth/2)-4,3,'.','') }}" x2="{{ number_format($x+($barWidth/2)+4,3,'.','') }}" y1="220" y2="220" class="annual-zero-month" vector-effect="non-scaling-stroke"><title>{{ $point['label']??\Carbon\Carbon::parse($point['date'])->format('m') }}/{{ $selectedYear }}: R$ 0,00</title></line>@endif
                            </g>
                        @endforeach
                    </svg>
                    <div class="annual-month-labels" style="--annual-month-count:{{ max(1,$pointCount) }}" aria-hidden="true">@foreach($points as $point)<span>{{ $point['label']??\Carbon\Carbon::parse($point['date'])->format('m') }}</span>@endforeach</div>
                </div>
            </div>
            <figcaption class="hint annual-chart-caption">Todos os meses do ano ficam visíveis. Meses sem movimentação permanecem em zero, inclusive meses futuros; não há projeção de faturamento neste gráfico.</figcaption>
        </figure>
        <details class="disclosure compact chart-values annual-chart-values" id="sales-trend-values"><summary>Conferir valores e vendas por mês</summary><div class="disclosure-content scroll" tabindex="0" role="region" aria-label="Valores mensais de vendas em {{ $selectedYear }}"><table><thead><tr><th>Mês</th><th class="num">Vendas concluídas</th><th class="num">Valor líquido vendido (R$)</th></tr></thead><tbody>@foreach($points as $point)<tr><td>{{ $point['label']??\Carbon\Carbon::parse($point['date'])->format('m') }}/{{ $selectedYear }}</td><td class="num">{{ number_format($point['count']??0,0,',','.') }}</td><td class="num">{{ number_format($point['total'],2,',','.') }}</td></tr>@endforeach</tbody></table></div></details>
    @else
        <p class="empty">A série mensal ainda não está disponível para esta visualização.</p>
    @endif
</section>
