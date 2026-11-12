@extends('layout')
@section('title','Resultado da simulação interna')
@section('subtitle','Sem valor fiscal · nenhum dado foi transmitido à SEFAZ.')
@section('content')
@php($snapshot=$simulation->snapshot)
<section class="panel"><div class="notice"><strong>SIMULAÇÃO INTERNA · SEM VALOR FISCAL</strong><p>Este registro não é uma NFC-e, autorização SEFAZ ou DANFE. Não altera a venda, estoque, financeiro ou caixa.</p></div><p><strong>Referência local:</strong> {{ $simulation->reference }}</p><p><strong>Venda:</strong> #{{ $simulation->sale_id }} · <strong>Resultado simulado:</strong> {{ ['accepted'=>'Aceite simulado','rejected'=>'Rejeição simulada','uncertain'=>'Timeout simulado · consulte para continuar','cancelled'=>'Cancelamento simulado'][$simulation->state]??$simulation->state }}</p>
<div class="actions"><a class="button secondary" href="{{ route('fiscal.simulations.receipt',['company'=>$tenant->slug,'id'=>$simulation->id]) }}">Abrir cupom de teste</a><a class="button secondary" href="{{ route('fiscal.simulations.download',['company'=>$tenant->slug,'id'=>$simulation->id]) }}">Baixar JSON da simulação</a></div>
@if($simulation->state==='uncertain')
<form method="post" action="{{ route('fiscal.simulations.consult',['company'=>$tenant->slug,'id'=>$simulation->id]) }}">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><p>A consulta reproduz uma resposta local para o timeout escolhido. Não consulta a SEFAZ.</p><button class="primary">Consultar resultado simulado</button></form>
@endif
@if($simulation->state==='accepted')
<details class="disclosure" style="margin-top:24px"><summary>Testar cancelamento local</summary><form class="disclosure-content" method="post" action="{{ route('fiscal.simulations.cancel',['company'=>$tenant->slug,'id'=>$simulation->id]) }}">@csrf<input type="hidden" name="request_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><label for="simulation-reason">Motivo do teste de cancelamento (15 a 255 caracteres)</label><textarea id="simulation-reason" name="reason" minlength="15" maxlength="255" required></textarea><div class="actions"><button class="button secondary">Simular cancelamento</button></div></form></details>
@endif
</section>
<section class="panel"><h2>Pendências para homologação SEFAZ</h2><p>Estas pendências não impediram o teste local e não são resolvidas por um aceite simulado.</p><ul>@forelse(($snapshot['readiness']['blockers']??[]) as $blocker)<li>{{ $blocker['message'] }}</li>@empty<li>Nenhuma pendência capturada nesta conferência. Isso não representa autorização da SEFAZ.</li>@endforelse</ul></section>
<section class="panel"><h2>Histórico do teste</h2><div class="scroll"><table><thead><tr><th>Quando</th><th>Ação local</th><th>Quem registrou</th><th>Motivo</th></tr></thead><tbody>@foreach($simulation->events as $event)<tr><td>{{ $event->created_at->format('d/m/Y H:i') }}</td><td>{{ ['create'=>'Início da simulação','consult'=>'Consulta simulada','cancel'=>'Cancelamento simulado'][$event->operation]??$event->operation }}</td><td>{{ $event->actor?->name??'Não informado' }}</td><td>{{ $event->reason??'—' }}</td></tr>@endforeach</tbody></table></div></section>
<p><a href="{{ route('fiscal.simulations.index',['company'=>$tenant->slug]) }}">Voltar às simulações internas</a></p>
@endsection
