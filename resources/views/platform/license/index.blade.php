@extends('platform.layout')
@section('title','Contratos e parcelas')
@section('subtitle',$company->name)
@section('actions')
<a class="button secondary" href="{{ route('platform.companies.show',['id'=>$company->id]) }}">Voltar à empresa</a>
@endsection
@section('content')
@include('platform.company-navigation')
<section class="panel central-contracts">
    <div class="section-heading"><h2>Contratos</h2><a class="button" href="{{ route('platform.license.create',['id'=>$company->id]) }}">Novo contrato</a></div>
    @include('license.contracts', ['central'=>true])
</section>
<section class="panel central-contracts"><h2>Parcelas da licença</h2>@include('license.installments', ['central'=>true])</section>
@endsection
