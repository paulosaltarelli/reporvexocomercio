@extends('platform.layout')
@section('title',$contract->exists?'Editar contrato #'.$contract->id:'Novo contrato')
@section('subtitle',$company->name)
@section('content')
@include('platform.company-navigation')
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
    <fieldset class="central-fieldset">
        <legend>Carências do contrato</legend>
        <div class="form-grid">
            <div class="field">
                <label for="implantation-grace">Implantação sem cobrança (dias corridos)</label>
                <input id="implantation-grace" type="number" name="implantation_grace_days" min="0" max="3650" step="1" value="{{ old('implantation_grace_days',$contract->implantation_grace_days ?? 0) }}" aria-describedby="implantation-grace-help">
                <small class="hint" id="implantation-grace-help">Exemplo: com início em 01/04 e 90 dias de implantação, a cobrança pode começar em 30/06. Se o vencimento for dia 10, a primeira parcela vence em 10/07, desde que o contrato ainda esteja vigente.</small>
            </div>
            <div class="field">
                <label for="overdue-grace">Tolerância após o vencimento (dias corridos)</label>
                <input id="overdue-grace" type="number" name="overdue_grace_days" min="0" max="3650" step="1" value="{{ old('overdue_grace_days',$contract->overdue_grace_days ?? 0) }}" aria-describedby="overdue-grace-help">
                <small class="hint" id="overdue-grace-help">Exemplo: se a parcela vencer no dia 10 e a tolerância for de 5 dias, os recursos selecionados podem ser restringidos a partir do dia 16 enquanto houver saldo em aberto. Com zero dias, a restrição pode começar no dia 11.</small>
            </div>
        </div>
    </fieldset>
    <fieldset class="central-fieldset"><legend>Recursos sujeitos à restrição por atraso</legend>
        <p class="hint">Selecione os recursos que podem ser limitados após a tolerância das parcelas vencidas. Deixe a lista vazia para manter todos disponíveis.</p>
        @php($selected = session()->hasOldInput() ? old('restricted_modules',[]) : ($contract->restricted_modules ?? []))
        @foreach($modules as $key => $label)
            <label class="checkbox-line"><input type="checkbox" name="restricted_modules[]" value="{{ $key }}" @checked(is_array($selected) && in_array($key,$selected,true))> {{ $label }}</label>
        @endforeach
    </fieldset>
    <p class="hint">Ao salvar, as parcelas são calculadas a partir da vigência e do vencimento.</p>
    <div class="actions"><button class="button">{{ $contract->exists?'Salvar alterações':'Criar contrato e parcelas' }}</button><a href="{{ route('platform.license.index',['id'=>$company->id]) }}">Cancelar</a></div>
</form>
@endsection
