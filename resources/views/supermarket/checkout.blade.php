<section id="market-payment" class="operation-panel operation-checkout market-checkout" data-operation-checkout
    @if($errors->any() && (string)old('_checkout_cart') === (string)$cart->id) data-checkout-errors @endif
    aria-labelledby="market-checkout-title">
    <div class="market-checkout-heading">
        <h2 id="market-checkout-title">Receber venda #{{ $cart->id }}</h2>
        <button class="button secondary" type="button" data-market-checkout-close hidden>Voltar ao caixa <kbd>Esc</kbd></button>
    </div>
    <form method="post" action="{{ route('supermarket.checkout',['company'=>$tenant->slug,'cart'=>$cart->id]) }}"
          data-payment-total="{{ bcmul((string)$cart->total,'100',0) }}">
        @csrf
        <input type="hidden" name="_checkout_cart" value="{{ $cart->id }}">
        <input type="hidden" name="version" value="{{ $cart->version }}">
        <div class="market-checkout-total" role="status" aria-atomic="true">
            <span>Total da venda</span>
            <strong data-payment-grand-total>R$ {{ number_format($cart->total,2,',','.') }}</strong>
        </div>
        @include('operations.payment-fields')
        <div class="field">
            <label for="supermarket-customer">Cliente</label>
            <select id="supermarket-customer" name="customer_id">
                <option value="">Consumidor final</option>
                @foreach($customers as $customer)
                    <option value="{{ $customer->id }}" @selected((string)old('customer_id',$cart->customer_id)===(string)$customer->id)>{{ $customer->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="button" type="submit" data-market-checkout-confirm>Confirmar venda e recebimento <kbd data-market-confirm-shortcut hidden>Ctrl + Enter</kbd></button>
    </form>
</section>
@push('scripts')
    <script src="/supermarket-checkout.js?v={{ filemtime(public_path('supermarket-checkout.js')) }}" defer></script>
@endpush
