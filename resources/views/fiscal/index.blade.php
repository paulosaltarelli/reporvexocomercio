@extends('layout')
@section('title','Fiscal · homologação')
@section('subtitle','Configuração, conferência e acompanhamento de NFC-e em homologação.')
@section('content')
@include('fiscal.nfse.entry')
<div class="actions no-print"><a class="button" href="{{ route('sales',['company'=>$tenant->slug]) }}">Escolher venda para simular</a></div>

<section class="panel"><h2>Comece sem certificado</h2><p>Teste aceite, rejeição, timeout, consulta e cancelamento em uma simulação interna. Nenhuma informação é enviada à SEFAZ; o resultado não tem valor fiscal.</p><a class="button secondary" href="{{ route('fiscal.simulations.index',['company'=>$tenant->slug]) }}">Abrir simulações internas</a><p class="hint">Para iniciar um teste, abra a conferência fiscal de uma venda. O fluxo de homologação SEFAZ abaixo permanece separado e exige as configurações correspondentes.</p></section>

<section class="panel"><h2>Situação da empresa</h2>
<p>Os dados do emitente vêm de <a href="{{ route('business',['company'=>$tenant->slug]) }}">Minha empresa</a>. A conferência local não comprova cadastro ativo na Receita nem credenciamento na SEFAZ.</p>
@include('fiscal.blockers')
</section>

<section class="panel"><h2>Conferências registradas</h2><p>Para criar uma conferência, abra uma venda e selecione “Conferir dados fiscais”.</p>
<div class="scroll"><table><thead><tr><th>Conferência</th><th>Venda</th><th>Data</th><th>Situação</th></tr></thead><tbody>
@forelse($preparations as $preparation)<tr><td><a href="{{ route('fiscal.preparation',['company'=>$tenant->slug,'id'=>$preparation->id]) }}">#{{ $preparation->id }}</a></td><td>#{{ $preparation->sale_id }}</td><td>{{ $preparation->created_at->format('d/m/Y H:i') }}</td><td>Conferência · sem emissão</td></tr>@empty<tr><td colspan="4">Nenhuma conferência registrada.</td></tr>@endforelse
</tbody></table></div>{{ $preparations->links() }}</section>
<section class="panel"><h2>Notas fiscais</h2><p>Configure certificado e credenciais em <a href="{{ route('business',['company'=>$tenant->slug]) }}#fiscal-company">Minha empresa</a>. Acompanhe abaixo o resultado das solicitações de emissão.</p><div class="scroll"><table><thead><tr><th>Número / série</th><th>Venda</th><th>Situação</th></tr></thead><tbody>@forelse($documents as $doc)<tr><td><a href="{{ route('fiscal.document',['company'=>$tenant->slug,'id'=>$doc->id]) }}">{{ $doc->number }} / {{ $doc->series }}</a></td><td>#{{ $doc->sale_id }}</td><td>{{ ['pending'=>'Na fila','sent'=>'Aguardando confirmação','uncertain'=>'Consulta necessária','authorized'=>'Autorizada em homologação','rejected'=>'Rejeitada','cancelled'=>'Cancelada em homologação'][$doc->state]??$doc->state }}</td></tr>@empty<tr><td colspan="3">Nenhuma emissão solicitada.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><h2>Perfis tributários e herança</h2><p>Reutilize parâmetros fiscais entre produtos e grupos, preservando as exceções individuais. Alterações afetam futuras conferências e emissões; registros históricos não são reescritos.</p><a class="button secondary" href="{{ route('fiscal.profiles.index',['company'=>$tenant->slug]) }}">Gerenciar perfis tributários</a></section>
<section class="panel"><h2>Regras por produto</h2><p>Cadastre os parâmetros específicos validados pelo contador antes da emissão.</p><ul>@foreach($taxProducts as $product)<li><a href="{{ route('fiscal.product',['company'=>$tenant->slug,'id'=>$product->id]) }}">{{ $product->name }}</a></li>@endforeach</ul></section>
<section class="panel"><h2>Inutilização de numeração</h2><p>Indisponível nesta etapa da integração direta com a SEFAZ. Nenhuma faixa pode ser inutilizada por esta tela.</p></section>
@endsection
