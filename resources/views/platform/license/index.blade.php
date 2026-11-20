@extends('platform.layout')
@section('title','Licença de '.$company->name)
@section('subtitle','Consulte a vigência dos contratos, as mensalidades e os pagamentos da empresa.')
@section('actions')
<a class="button secondary" href="{{ route('platform.companies.show',['id'=>$company->id]) }}">Voltar à empresa</a>
@endsection
@section('content')
<section class="panel">
    <div class="section-heading"><h2>Contratos</h2><a class="button" href="{{ route('platform.license.create',['id'=>$company->id]) }}">Novo contrato</a></div>
    @include('license.contracts', ['central'=>true])
</section>
<section class="panel"><h2>Parcelas da licença</h2>@include('license.installments', ['central'=>true])</section>
@endsection
