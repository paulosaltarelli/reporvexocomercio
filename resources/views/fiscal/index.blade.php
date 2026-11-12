@extends('layout')
@section('title','Preparação fiscal')
@section('subtitle','Confira o cadastro da empresa e prepare a homologação. Emissão ainda desabilitada.')
@section('content')
<section class="panel"><h2>Situação da empresa</h2>
<p>Os dados do emitente vêm de <a href="{{ route('business',['company'=>$tenant->slug]) }}">Minha empresa</a>. A conferência local não comprova cadastro ativo na Receita nem credenciamento na SEFAZ.</p>
@include('fiscal.blockers')
</section>
<form class="panel" method="post" action="{{ route('fiscal.settings',['company'=>$tenant->slug]) }}">@csrf
<h2>Configuração de homologação</h2><div class="form-grid">
<div class="field"><label for="environment">Ambiente</label><select id="environment" name="environment"><option value="homologation">Homologação · sem valor fiscal</option></select></div>
<div class="field"><label for="provider">Integração proposta</label><select id="provider" name="provider"><option value="focus">Focus NFe · ainda não conectada</option></select></div>
<div class="field"><label for="operation">Operação pretendida</label><select id="operation" name="operation"><option value="">Não informada</option>@foreach(['presential'=>'Presencial','delivery'=>'Delivery','both'=>'Presencial e delivery'] as $value=>$label)<option value="{{ $value }}" @selected(old('operation',$settings?->operation)===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="field"><label for="nfce_accredited">Credenciamento NFC-e de homologação</label><select id="nfce_accredited" name="nfce_accredited"><option value="0">Não confirmado</option><option value="1" @selected((string)old('nfce_accredited',(int)($settings?->nfce_accredited??false))==='1')>Confirmado pelo responsável na SEFAZ</option></select></div>
<div class="field"><label for="accountant_name">Contador responsável</label><input id="accountant_name" name="accountant_name" maxlength="180" value="{{ old('accountant_name',$settings?->accountant_name) }}"></div>
<div class="field"><label for="accountant_email">E-mail do contador</label><input id="accountant_email" name="accountant_email" type="email" maxlength="180" value="{{ old('accountant_email',$settings?->accountant_email) }}"></div>
</div><p class="hint">Informar o contador e o credenciamento não aprova regras tributárias nem libera produção. Não cadastre certificado, senha, CSC ou token neste formulário.</p>
<button class="primary">Salvar configuração de homologação</button></form>
<section class="panel"><h2>Conferências registradas</h2><p>Para criar uma conferência, abra uma venda e selecione “Conferir dados fiscais”.</p>
<div class="scroll"><table><thead><tr><th>Conferência</th><th>Venda</th><th>Data</th><th>Situação</th></tr></thead><tbody>
@forelse($preparations as $preparation)<tr><td><a href="{{ route('fiscal.preparation',['company'=>$tenant->slug,'id'=>$preparation->id]) }}">#{{ $preparation->id }}</a></td><td>#{{ $preparation->sale_id }}</td><td>{{ $preparation->created_at->format('d/m/Y H:i') }}</td><td>Conferência · sem emissão</td></tr>@empty<tr><td colspan="4">Nenhuma conferência registrada.</td></tr>@endforelse
</tbody></table></div>{{ $preparations->links() }}</section>
@endsection
