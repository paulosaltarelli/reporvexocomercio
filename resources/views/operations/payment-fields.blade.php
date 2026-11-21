<div class="field">
    <label for="operation-payment">Forma de pagamento</label>
    @include('components.payment-method-select',['paymentSelectId'=>'operation-payment','paymentDefault'=>'cash'])
</div>
<div class="field">
    <label for="operation-paid">Recebimento</label>
    <select id="operation-paid" name="paid" data-operation-paid>
        <option value="1" @selected((string)old('paid','1')==='1')>Recebido agora</option>
        <option value="0" @selected((string)old('paid')==='0')>A receber</option>
    </select>
</div>
<div data-operation-credit @if((string)old('paid','1')!=='0') hidden @endif>
    <div class="form-grid">
        <div class="field">
<label for="operation-due">Primeiro vencimento</label>
<input id="operation-due" type="date" name="due_date" value="{{ old('due_date') }}">
</div>
        <div class="field">
<label for="operation-installments">Parcelas</label>
<input id="operation-installments" type="number" name="installments" min="1" max="24" value="{{ old('installments',1) }}">
</div>
    </div>
</div>
@include('components.sales.card-fees')
<p class="card-sale-total notice" role="status" hidden>
</p>
@include('components.sales.seller-field')
<p class="hint">Confira o recebimento antes de finalizar. Pix e cartões são registrados manualmente.</p>
