@extends('layout')
@section('title','Unidades de medida')
@section('content')
<form class="panel" method="post" action="{{ route('units.store',['company'=>$tenant->slug]) }}">
@csrf
<h2>Cadastrar unidade</h2>
<div class="form-grid"><div class="field"><label for="unit-code">Sigla</label><input id="unit-code" name="code" pattern="[A-Z][A-Z0-9]{0,9}" maxlength="10" placeholder="SC" required></div>
<div class="field"><label for="unit-name">Descrição</label><input id="unit-name" name="label" maxlength="60" placeholder="Saco" required></div></div>
<button class="primary">Cadastrar unidade</button></form>
<section class="panel"><h2>Unidades da loja</h2><table><thead><tr><th>Sigla</th><th>Descrição</th></tr></thead><tbody>
@foreach(\App\Services\MeasureUnits::catalog($operationConfig) as $code=>$label)<tr><td>{{ $code }}</td><td>{{ $label }}</td></tr>@endforeach
</tbody></table></section>
@endsection
