<form id="document-checkout" class="no-print" data-payment-total="{{ bcmul($document->total,'100',0) }}" method="post" action="{{ route('document.invoice',['company'=>$tenant->slug,'id'=>$document->id]) }}">
    @csrf
    <h2 style="margin-top:30px">Receber no caixa e gerar venda</h2>
    <div class="form-grid">
        <div class="field">
            <label for="document-invoice-seller">Vendedor creditado</label>
            <input id="document-invoice-seller" value="{{ $document->seller_name??'Não informado' }}" readonly>
            <small class="hint">A venda herda o vendedor deste documento. Quem confirmar a operação será registrado separadamente.</small>
        </div>
        <div class="single-payment-field">
            <label for="payment-option">Forma de pagamento</label>
            @include('components.payment-method-select',['paymentSelectId'=>'payment-option','paymentDefault'=>'cash'])
        </div>
        <div class="single-payment-field">
            <label for="document-paid">Recebimento</label>
            <select id="document-paid" name="paid">
                <option value="1" @selected((string)old('paid',1)==='1')>Já recebido</option>
                <option value="0" @selected((string)old('paid',1)==='0')>A receber</option>
            </select>
        </div>
        <div class="single-payment-field">
            <label for="document-installments">Número de parcelas</label>
            <input id="document-installments" name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required>
            <small class="hint">Parcelas mensais para vendas a receber.</small>
        </div>
        <div class="single-payment-field">
            <label for="document-due-date">Primeiro vencimento se pendente</label>
            <input id="document-due-date" type="date" name="due_date" value="{{ old('due_date') }}">
        </div>
    </div>
    @include('components.sales.card-fees')
    @include('components.sales.split-payments')
    <p class="card-sale-total notice" role="status" hidden></p>
    <div class="actions"><button class="primary">Confirmar venda e baixar estoque</button></div>
    <p class="hint">Não emite nota fiscal nem realiza cobrança automática.</p>
</form>
