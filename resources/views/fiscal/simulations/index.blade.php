@extends('layout')
@section('title','Simulações fiscais internas')
@section('subtitle','Teste o fluxo sem certificado e sem comunicação com a SEFAZ. Nenhum documento tem valor fiscal.')
@section('content')
<div class="actions no-print"><a class="button" href="{{ route('sales',['company'=>$tenant->slug]) }}">Escolher venda para simular</a></div>

<section class="panel"><h2>Simulação interna ou homologação?</h2><p>A simulação reproduz respostas de teste dentro do Vendra. Não reserva numeração, não emite NFC-e e não altera estoque, recebimentos ou caixa.</p><p>Para iniciar, abra a conferência fiscal de uma venda e escolha um cenário. As pendências para a SEFAZ são exibidas separadamente e não impedem o teste local.</p><a href="{{ route('fiscal',['company'=>$tenant->slug]) }}">Voltar à área fiscal</a></section>
<section class="panel"><h2>Histórico de testes</h2><div class="scroll"><table><thead><tr><th>Referência local</th><th>Venda</th><th>Data</th><th>Resultado simulado</th><th></th></tr></thead><tbody>
@forelse($simulations as $simulation)
<tr><td>{{ $simulation->reference }}</td><td>#{{ $simulation->sale_id }}</td><td>{{ $simulation->created_at->format('d/m/Y H:i') }}</td><td>{{ ['accepted'=>'Aceite simulado','rejected'=>'Rejeição simulada','uncertain'=>'Timeout simulado','cancelled'=>'Cancelamento simulado'][$simulation->state]??$simulation->state }}</td><td><a href="{{ route('fiscal.simulations.show',['company'=>$tenant->slug,'id'=>$simulation->id]) }}">Abrir teste</a></td></tr>
@empty<tr><td colspan="5" class="empty">Nenhuma simulação interna registrada.</td></tr>@endforelse
</tbody></table></div>{{ $simulations->links() }}</section>
@endsection
