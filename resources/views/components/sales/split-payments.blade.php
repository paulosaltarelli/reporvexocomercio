@php($splitOptions=app(\App\Services\PaymentMethods::class)->options())
<details class="split-payments no-print" @if(old('payments')||$errors->has('payments')||$errors->has('payments.*')) open @endif>
<summary>Dividir pagamento / entrada e saldo</summary>
<div class="split-payment-content">
<p class="hint">Distribua o total entre formas. Exemplo: entrada em Pix e restante no cartão ou a prazo. Ao ativar, esta composição substitui o pagamento único.</p>
<label class="checkbox-line"><input type="checkbox" class="split-enabled" @checked(old('payments'))> Usar pagamento dividido</label>
<div class="split-payment-rows"></div>
<button type="button" class="button secondary split-add">+ Adicionar forma</button>
<p class="split-payment-balance hint" role="status" aria-live="polite"></p>
</div>
</details>
<template class="split-payment-template"><fieldset class="split-payment-row"><legend>Parte do pagamento</legend><div class="form-grid">
<div class="field"><label>Forma</label><select data-field="payment_option" required>@foreach($splitOptions as $id=>$method)<option value="{{ $id }}" data-type="{{ $method['type'] }}">{{ $method['label'] }}</option>@endforeach</select></div>
<div class="field"><label>Valor desta parte (R$)</label><input data-field="amount" type="number" min="0.01" max="999999999999.99" step="0.01" required></div>
<div class="field"><label>Recebimento</label><select data-field="paid"><option value="1">Recebido agora</option><option value="0">A receber</option></select></div>
<div class="field split-due"><label>Primeiro vencimento</label><input data-field="due_date" type="date" min="{{ now()->toDateString() }}"></div>
<div class="field split-due"><label>Parcelas mensais</label><input data-field="installments" type="number" min="1" max="24" value="1"></div>
<div class="field split-cash"><label>Dinheiro entregue (opcional)</label><input data-field="cash_received" type="number" min="0" max="999999999999.99" step="0.01"><small class="split-change hint"></small></div>
</div><button type="button" class="text-button split-remove">Remover forma</button></fieldset></template>
@push('scripts')<script>window.vendraSplitInitial=@json(array_values(old('payments',[])));</script><script src="/split-payments.js?v={{ filemtime(public_path('split-payments.js')) }}"></script>@endpush
