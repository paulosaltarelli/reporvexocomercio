@php($checkoutPaid=(string)(int)old('paid','1'))
@php($checkoutSellerId=old('salesperson_id',$selectedSalespersonId??$currentSalesperson?->id))
@php($checkoutSeller=$sellers->firstWhere('id',(int)$checkoutSellerId)??$currentSalesperson)
@php($checkoutBlocked=$currentUser->role!=='admin' && (!$currentSalesperson || !$currentSalesperson->active))
<form id="checkout-form" class="pos-checkout" method="post" action="{{ route('sales',['company'=>$tenant->slug]) }}">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
    <div class="pos-grid">
        <div class="pos-cart">
            @include('components.sales.entry-items',['itemsHeading'=>'Itens da venda','isPos'=>true])
        </div>
        <section class="panel pos-payment" aria-labelledby="pos-payment-title">
            <span class="eyebrow">VENDA ATUAL</span><h2 id="pos-payment-title">Resumo e pagamento</h2>
            <div class="pos-summary"><div><span>Subtotal</span><strong id="pos-gross-total">R$ 0,00</strong></div><div><span>Descontos</span><strong id="pos-discount-total">R$ 0,00</strong></div></div>
            <div class="pos-grand-total"><span>Total a pagar</span><strong id="pos-net-total" aria-live="polite">R$ 0,00</strong></div>
            <div class="field"><label for="pos-payment-method">Forma de pagamento</label><select id="pos-payment-method" name="payment_method">@foreach(['cash'=>'Dinheiro','pix'=>'Pix','credit'=>'Cartão de crédito','debit'=>'Cartão de débito','transfer'=>'Transferência','invoice'=>'A prazo'] as $value=>$label)<option value="{{ $value }}" @selected(old('payment_method','cash')===$value)>{{ $label }}</option>@endforeach</select></div>
            <div class="field"><label for="pos-paid">Recebimento</label><select id="pos-paid" name="paid"><option value="1" @selected($checkoutPaid==='1')>Recebido agora</option><option value="0" @selected($checkoutPaid==='0')>A receber</option></select></div>
            <div id="pos-credit-fields" @if($checkoutPaid!=='0') hidden @endif><div class="field"><label for="pos-due-date">Primeiro vencimento</label><input id="pos-due-date" type="date" name="due_date" value="{{ old('due_date') }}"></div><div class="field"><label for="pos-installments">Parcelas</label><input id="pos-installments" name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required></div></div>
            <div id="pos-cash-fields"><div class="field"><label for="pos-cash-received">Valor entregue (opcional)</label><input id="pos-cash-received" type="number" min="0" max="999999999999.99" step="0.01" inputmode="decimal" placeholder="0,00"></div><div class="pos-change"><span>Troco</span><strong id="pos-change">—</strong></div></div>
            <details class="pos-optionals" @if(old('customer_id',$selectedCustomerId??null)||$errors->hasAny(['customer_id','salesperson_id','notes'])) open @endif><summary>Cliente e vendedor <span>opcional</span></summary><div class="pos-optionals-content">
                <div class="field"><label for="pos-customer">Cliente</label><select id="pos-customer" name="customer_id"><option value="">Consumidor final</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)old('customer_id',$selectedCustomerId??null)===(string)$customer->id)>{{ $customer->name }}</option>@endforeach</select></div>
                @include('components.sales.seller-field')
                <div class="field"><label for="pos-notes">Observações</label><input id="pos-notes" name="notes" maxlength="1000" value="{{ old('notes') }}"></div>
            </div></details>
            <p class="pos-customer-summary">{{ $customers->firstWhere('id',(int)old('customer_id',$selectedCustomerId??0))?->name??'Consumidor final' }} · {{ $checkoutSeller?->name??'Selecione um vendedor' }}</p>
            <button id="checkout-submit" class="primary pos-finalize" @disabled($checkoutBlocked)>Registrar venda <kbd>F9</kbd></button>
            <p class="hint pos-payment-note">Confira o recebimento antes de finalizar. Pix e cartões são registrados manualmente.</p>
        </section>
    </div>
</form>
@push('scripts')<script src="/pos.js?v={{ filemtime(public_path('pos.js')) }}"></script>@endpush
