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
<section class="panel central-contracts"><h2>Parcelas da licença</h2>
<form class="platform-filters" method="get" action="{{ route('platform.license.index',['id'=>$company->id]) }}">
    @if(request()->filled('contratos'))<input type="hidden" name="contratos" value="{{ request('contratos') }}">@endif
    <div class="field"><label for="installment-scope">Exibir parcelas</label><select id="installment-scope" name="parcelas_status">
        @foreach(['all'=>'Todas, com as abertas primeiro','open'=>'Em aberto','history'=>'Histórico de pagas e canceladas'] as $value=>$label)<option value="{{ $value }}" @selected($installmentFilter===$value)>{{ $label }}</option>@endforeach
    </select></div><button type="submit" class="button secondary">Filtrar</button>
</form>
@include('license.installments', ['central'=>true])</section>
@endsection
