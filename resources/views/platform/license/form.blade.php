@extends('platform.layout')
@section('title',$contract->exists?'Editar contrato #'.$contract->id:'Novo contrato')
@section('subtitle',$company->name)
@section('content')
<form class="panel form-panel" method="post" action="{{ $contract->exists ? route('platform.license.update',['id'=>$company->id,'contract'=>$contract->id]) : route('platform.license.store',['id'=>$company->id]) }}">
    @csrf
    @if($contract->exists)<input type="hidden" name="expected_version" value="{{ old('expected_version',$contract->version) }}">@endif
    @if($contract->exists && $errors->has('expected_version'))<p><a href="{{ route('platform.license.edit',['id'=>$company->id,'contract'=>$contract->id]) }}">Reabrir a versão atual do contrato</a></p>@endif
    <h2>Vigência e cobrança mensal</h2>
    <div class="form-grid">
        <div class="field"><label for="contract-start">Início do contrato</label><input type="date" id="contract-start" name="start_date" value="{{ old('start_date',$contract->start_date?->format('Y-m-d')) }}" required></div>
        <div class="field"><label for="contract-end">Fim do contrato</label><input type="date" id="contract-end" name="end_date" value="{{ old('end_date',$contract->end_date?->format('Y-m-d')) }}" required></div>
        <div class="field"><label for="monthly-amount">Mensalidade (R$)</label><input type="number" id="monthly-amount" name="monthly_amount" min="0.01" step="0.01" value="{{ old('monthly_amount',$contract->monthly_amount) }}" required></div>
        <div class="field"><label for="due-day">Dia do vencimento</label><input type="number" id="due-day" name="due_day" min="1" max="31" value="{{ old('due_day',$contract->due_day) }}" required></div>
    </div>
    <fieldset><legend>Recursos sujeitos à restrição por atraso</legend>
        <p class="hint">Selecione os recursos que podem ser limitados quando houver parcelas vencidas. Deixe a lista vazia para manter todos disponíveis.</p>
        @php($selected = session()->hasOldInput() ? old('restricted_modules',[]) : ($contract->restricted_modules ?? []))
        @foreach($modules as $key => $label)
            <label class="checkbox-line"><input type="checkbox" name="restricted_modules[]" value="{{ $key }}" @checked(is_array($selected) && in_array($key,$selected,true))> {{ $label }}</label>
        @endforeach
    </fieldset>
    <p class="hint">Ao salvar, as parcelas são calculadas a partir da vigência e do vencimento.</p>
    <div class="actions"><button class="button">{{ $contract->exists?'Salvar alterações':'Criar contrato e parcelas' }}</button><a href="{{ route('platform.license.index',['id'=>$company->id]) }}">Cancelar</a></div>
</form>
@endsection
