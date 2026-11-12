@extends('layout')
@section('title','Minha empresa')
@section('subtitle','Identificação e dados preparatórios para a futura integração fiscal.')
@section('content')
<form class="panel" method="post">@csrf<h2>Identificação</h2><div class="form-grid">
@foreach(['legal_name'=>'Razão social','trade_name'=>'Nome fantasia','document'=>'CNPJ','email'=>'E-mail','phone'=>'Telefone'] as $field=>$label)
<div class="field"><label>{{ $label }}</label><input name="{{ $field }}" value="{{ old($field,$profile->$field) }}" type="{{ $field==='email'?'email':'text' }}" @required($field==='legal_name') maxlength="{{ ['document'=>18,'phone'=>30][$field]??180 }}"></div>
@endforeach</div>
<details class="disclosure" style="margin-top:24px"><summary>Endereço e dados fiscais preparatórios</summary><div class="disclosure-content form-grid">
@foreach(['street'=>'Logradouro','number'=>'Número','district'=>'Bairro','city'=>'Cidade','postal_code'=>'CEP','municipality_code'=>'Código IBGE do município','municipal_registration'=>'Inscrição municipal','state_registration'=>'Inscrição estadual'] as $field=>$label)
<div class="field"><label>{{ $label }}</label><input name="{{ $field }}" value="{{ old($field,$profile->$field) }}" maxlength="{{ ['street'=>180,'number'=>20,'district'=>100,'city'=>100,'postal_code'=>9,'municipality_code'=>7,'municipal_registration'=>30,'state_registration'=>30][$field] }}"></div>
@endforeach
<div class="field"><label>Estado</label><select name="state"><option value="">Não informado</option>
@foreach(explode(',','AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO') as $uf)<option @selected(old('state',$profile->state)===$uf)>{{ $uf }}</option>
@endforeach</select></div><div class="field"><label>Regime tributário</label><select name="tax_regime"><option value="">Não informado</option>
@foreach(['mei'=>'MEI','simples'=>'Simples Nacional','presumido'=>'Lucro Presumido','real'=>'Lucro Real'] as $value=>$label)<option value="{{ $value }}" @selected(old('tax_regime',$profile->tax_regime)===$value)>{{ $label }}</option>
@endforeach</select></div></div></details><p class="hint">Dados cadastrais sujeitos à conferência contábil. Não há cálculo de tributos, emissão de nota, integração municipal ou validação cadastral na Receita nesta etapa.</p><div class="actions"><button class="primary">Salvar dados da empresa</button></div></form>
@endsection
