@php
    $series = array_values($projectionRows ?? []);
    $seriesCount = count($series);
    $balances = array_map(fn($row)=>(float)$row['balance'],$series);
    $minimum = $seriesCount ? min(0.0,...$balances) : 0.0;
    $maximum = $seriesCount ? max(0.0,...$balances) : 0.0;
    $equal = $maximum===$minimum;
    if($equal){$minimum-=1.0;$maximum+=1.0;}
    $range=$maximum-$minimum;
    $yOf=fn($value)=>12+($maximum-(float)$value)/$range*156;
    $xOf=fn($index)=>$seriesCount>1?$index/($seriesCount-1)*800:400;
    $zeroY=$yOf(0);
    $line=implode(' ',array_map(fn($index)=>($index===0?'M':'L').number_format($xOf($index),3,'.','').','.number_format($yOf($series[$index]['balance']),3,'.',''),array_keys($series)));
    $axisLabel=fn($value)=>abs($value)>=1000000?number_format($value/1000000,1,',','.').' mi':(abs($value)>=1000?number_format($value/1000,1,',','.').' mil':number_format($value,2,',','.'));
@endphp
<section class="panel chart-panel" aria-labelledby="cashflow-chart-heading">
    <div class="section-heading chart-heading"><div><h2 id="cashflow-chart-heading">Caminho do saldo projetado</h2><p class="hint">Saldo ao fim de cada dia · horizonte de {{ $horizon }} dias</p></div><a href="#forecast-daily-table" data-open-disclosure="forecast-daily-table">Conferir os valores</a></div>
    @if($seriesCount)
        <figure class="data-chart">
            <div class="chart-legend"><span><i class="legend-marker blue line"></i>Saldo projetado</span><span><i class="legend-marker zero"></i>Referência zero</span></div>
            <div class="chart-layout">
                <div class="chart-y-axis" aria-hidden="true"><span>@if(!$equal){{ $axisLabel($maximum) }}@endif</span><span>{{ $axisLabel(($maximum+$minimum)/2) }}</span><span>@if(!$equal){{ $axisLabel($minimum) }}@endif</span></div>
                <div class="chart-plot">
                    <svg viewBox="0 0 800 180" preserveAspectRatio="none" class="chart-svg" role="img" aria-labelledby="cashflow-svg-title cashflow-svg-description">
                        <title id="cashflow-svg-title">Evolução do saldo projetado nos próximos {{ $horizon }} dias</title>
                        <desc id="cashflow-svg-description">A linha usa o saldo inicial informado e os saldos previstos de cada dia. A referência pontilhada marca zero. Previsão não é dinheiro confirmado. Os valores exatos estão na tabela diária abaixo.</desc>
                        @foreach([12,90,168] as $gridY)<line x1="0" y1="{{ $gridY }}" x2="800" y2="{{ $gridY }}" class="chart-grid-line" vector-effect="non-scaling-stroke"/>@endforeach
                        <line x1="0" y1="{{ number_format($zeroY,3,'.','') }}" x2="800" y2="{{ number_format($zeroY,3,'.','') }}" class="chart-zero-line" vector-effect="non-scaling-stroke"/>
                        <path d="{{ $line }}" class="chart-balance-line" vector-effect="non-scaling-stroke"/>
                        @foreach($series as $row)
                            @php($x=$xOf($loop->index))
                            <circle cx="{{ number_format($x,3,'.','') }}" cy="{{ number_format($yOf($row['balance']),3,'.','') }}" r="{{ $seriesCount>30?'2':'3' }}" class="chart-point"><title>{{ \Carbon\Carbon::parse($row['date'])->format('d/m/Y') }}: saldo projetado R$ {{ number_format($row['balance'],2,',','.') }}</title></circle>
                        @endforeach
                    </svg>
                    <div class="chart-x-axis" aria-hidden="true"><span>{{ \Carbon\Carbon::parse($series[0]['date'])->format('d/m') }}</span>@if($seriesCount>2)<span>{{ \Carbon\Carbon::parse($series[(int)floor(($seriesCount-1)/2)]['date'])->format('d/m') }}</span>@endif<span>{{ \Carbon\Carbon::parse($series[$seriesCount-1]['date'])->format('d/m') }}</span></div>
                </div>
            </div>
            <figcaption class="hint">Projeção com os compromissos em aberto, sem considerar novas vendas ou novas despesas que ainda não foram cadastradas. Valores em R$.</figcaption>
        </figure>
    @else
        <p class="empty">Ainda não há uma série de projeção disponível.</p>
    @endif
</section>
