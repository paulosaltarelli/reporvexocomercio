@extends('layout')
@section('title',$profile?'Editar perfil tributário':'Novo perfil tributário')
@section('subtitle','Parâmetros compartilhados da empresa, sem códigos ou alíquotas presumidos.')
@section('content')
<form class="panel" method="post" action="{{ $profile?route('fiscal.profiles.update',['company'=>$tenant->slug,'id'=>$profile->id]):route('fiscal.profiles.store',['company'=>$tenant->slug]) }}">
@csrf
@if($profile)<input type="hidden" name="revision" value="{{ $profile->revision }}">@endif
<div class="notice">Este perfil não compartilha GTIN, CEST, NCM, origem ou unidade. Alterações afetam os produtos que o utilizam em futuras conferências e emissões. Exceções individuais e documentos históricos são preservados.</div>
<div class="form-grid"><div class="field"><label for="profile-name">Nome do perfil</label><input id="profile-name" name="name" value="{{ old('name',$profile?->name) }}" maxlength="120" required></div><div class="field"><label for="profile-active">Disponibilidade</label><select id="profile-active" name="active"><option value="1" @selected((string)old('active',(int)($profile?->active??true))==='1')>Ativo</option><option value="0" @selected((string)old('active',(int)($profile?->active??true))==='0')>Inativo · bloqueia uso na emissão</option></select></div></div>
@php($p=$profile?->parameters??[])
@include('fiscal.parameter-fields',['accountantReference'=>$profile?->accountant_reference])
<p class="hint">Confirme os parâmetros com o responsável tributário. Não use um código apenas para contornar uma limitação do sistema. O tratamento IBS/CBS não aplicável permite revisão, mas bloqueia emissão neste escopo.</p>
<div class="actions"><button class="primary">{{ $profile?'Salvar nova revisão do perfil':'Criar perfil tributário' }}</button><a href="{{ route('fiscal.profiles.index',['company'=>$tenant->slug]) }}">Voltar aos perfis</a></div></form>
@endsection
