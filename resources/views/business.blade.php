@extends('layout')
@section('title','Minha empresa')
@section('subtitle','Cadastro, identidade e configuração fiscal por empresa.')
@section('content')
@if($currentUser->allows('administration'))<p class="no-print"><a href="{{ route('setup',['company'=>$tenant->slug]) }}">Operação e implantação: acompanhar etapas e módulos da empresa</a></p>@else<p class="hint">Os dados cadastrais são mantidos pelo administrador da empresa. Seu perfil permite configurar as operações fiscais abaixo.</p>@endif
@include('components.company-profile-form')
@if($currentUser->allows('fiscal.manage'))
@include('fiscal.company')
@endif
@endsection
