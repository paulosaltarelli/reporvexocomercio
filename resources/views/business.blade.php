@extends('layout')
@section('title','Minha empresa')
@section('subtitle','Dados cadastrais e identidade da empresa.')
@section('content')
@if($currentUser->allows('administration'))<p class="no-print"><a href="{{ route('setup',['company'=>$tenant->slug]) }}">Operação e implantação: acompanhar etapas e módulos da empresa</a></p>@else<p class="hint">Os dados cadastrais são mantidos pelo administrador da empresa.</p>@endif
@include('components.company-profile-form')
@if(request()->routeIs('business') && $currentUser->allows('administration'))
@include('storefront.publication')
@endif
@if($nfceAvailable && $currentUser->allows('fiscal.manage'))
@include('fiscal.company')
@endif
@endsection
