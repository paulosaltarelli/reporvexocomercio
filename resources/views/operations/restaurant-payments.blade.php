@if(isset($paymentSummary) && $order->status==='open' && $currentUser->allows('restaurant.receive') && \Illuminate\Support\Facades\Route::has('restaurant.payments.store'))
<section class="operation-panel operation-payment-summary">
    <h2>Recebimentos da comanda</h2>
    <dl class="operation-money-summary"><div><dt>Consumo</dt><dd>R$ {{ number_format($paymentSummary['total'],2,',','.') }}</dd></div><div><dt>Recebido</dt><dd>R$ {{ number_format($paymentSummary['received'],2,',','.') }}</dd></div>@if(bccomp($paymentSummary['passed_fee'],'0',2)>0)<div><dt>Taxas repassadas</dt><dd>R$ {{ number_format($paymentSummary['passed_fee'],2,',','.') }}</dd></div>@endif<div><dt>Saldo do consumo</dt><dd>R$ {{ number_format($paymentSummary['balance'],2,',','.') }}</dd></div></dl>
    @if(bccomp($paymentSummary['balance'],'0',2)>0)
    <details class="operation-partial" data-partial-payment @if(old('allocation_type') || $errors->hasAny(['amount','share_id','items'])) open @endif>
        <summary>Receber uma parte</summary>
        <form method="post" action="{{ route('restaurant.payments.store',['company'=>$tenant->slug,'order'=>$order->id]) }}" data-restaurant-partial data-payment-total="0">
            @csrf
            <input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label for="partial-allocation">Como dividir este recebimento?</label><select id="partial-allocation" name="allocation_type" data-partial-allocation><option value="amount" @selected(old('allocation_type','amount')==='amount')>Por valor</option><option value="person" data-allocation-choice @selected(old('allocation_type')==='person') disabled>Por pessoa</option><option value="items" data-allocation-choice @selected(old('allocation_type')==='items') disabled>Por itens consumidos</option></select></div>
            <div class="field" data-partial-person hidden><label for="partial-share">Pessoa</label><select id="partial-share" name="share_id" disabled><option value="">Selecione a pessoa</option>@foreach($paymentSummary['shares'] as $share)@if(bccomp($share['balance'],'0',2)>0)<option value="{{ $share['id'] }}" data-share-balance="{{ $share['balance'] }}" @selected((string)old('share_id')===(string)$share['id'])>{{ $share['person_name'] }} · saldo R$ {{ number_format($share['balance'],2,',','.') }}</option>@endif @endforeach</select></div>
            <div data-partial-items hidden>
                <p class="hint">Marque os itens e a quantidade consumida. O servidor calcula o valor e confere o que já foi recebido.</p>
                @foreach($paymentSummary['item_remaining'] as $part)
                @if(bccomp($part['remaining_quantity'],'0',3)>0)
                @php
                    $oldItemRows=is_array(old('items'))?old('items'):[];
                    $oldItemPart=collect($oldItemRows)->first(fn($row)=>is_array($row) && (string)($row['item_id']??'')===(string)$part['item_id']);
                    $oldItemQuantity=is_array($oldItemPart) && is_scalar($oldItemPart['quantity']??null)?$oldItemPart['quantity']:$part['remaining_quantity'];
                @endphp
                <div class="operation-payment-item" data-partial-item data-item-quantity="{{ $part['quantity'] }}" data-item-total="{{ $part['amount'] }}" data-item-remaining="{{ $part['remaining_quantity'] }}" data-item-remaining-amount="{{ $part['remaining_amount']??$part['amount'] }}">
                    <label class="checkbox-line"><input type="checkbox" data-partial-item-selected @checked($oldItemPart!==null)><span>{{ $part['description'] }}</span></label>
                    <input type="hidden" name="items[{{ $loop->index }}][item_id]" value="{{ $part['item_id'] }}" disabled>
                    <div class="field"><label for="partial-item-{{ $part['item_id'] }}">Quantidade · disponível {{ number_format($part['remaining_quantity'],3,',','.') }}</label><input id="partial-item-{{ $part['item_id'] }}" name="items[{{ $loop->index }}][quantity]" type="number" min="0.001" max="{{ $part['remaining_quantity'] }}" step="0.001" value="{{ $oldItemQuantity }}" data-partial-item-quantity disabled></div>
                </div>
                @endif
                @endforeach
            </div>
            <div class="field" data-partial-amount><label for="partial-amount">Valor do consumo a receber (R$)</label><input id="partial-amount" name="amount" type="number" min="0.01" max="{{ $paymentSummary['balance'] }}" step="0.01" value="{{ old('amount') }}" required inputmode="decimal"></div>
            <p class="hint" data-partial-total role="status"></p>
            <div class="field"><label for="partial-method">Forma efetivamente recebida</label>@include('components.payment-method-select',['paymentSelectId'=>'partial-method','paymentDefault'=>'cash'])</div>
            <div class="field" data-partial-cash><label for="partial-cash">Dinheiro entregue (opcional)</label><input id="partial-cash" name="cash_received" type="number" min="0" max="999999999999.99" step="0.01" inputmode="decimal" value="{{ old('cash_received') }}"><small class="hint" data-partial-change role="status"></small></div>
            @include('components.sales.card-fees')
            <p class="card-sale-total notice" role="status" hidden></p>
            <label class="checkbox-line operation-receipt-confirm"><input type="checkbox" name="receipt_confirmed" value="1" required> Conferi e recebi este valor. Pix e cartões são confirmados manualmente.</label>
            <button class="button" type="submit">Registrar recebimento parcial</button>
            <noscript><p class="hint">O recebimento por valor continua disponível. A seleção de pessoas e itens usa JavaScript.</p></noscript>
        </form>
    </details>
    @if(\Illuminate\Support\Facades\Route::has('restaurant.divisions.store'))
    <details class="operation-partial">
        <summary>Dividir saldo entre pessoas</summary>
        <form method="post" action="{{ route('restaurant.divisions.store',['company'=>$tenant->slug,'order'=>$order->id]) }}" data-restaurant-division data-division-initial="{{ json_encode(is_array(old('names'))?array_values(old('names')):[]) }}">
            @csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <div class="field"><label for="division-people">Número de pessoas</label><input id="division-people" name="people_count" type="number" min="1" max="100" value="{{ old('people_count',2) }}" required data-division-count></div>
            <div class="operation-division-names" data-division-names></div>
            <p class="hint">O servidor divide o saldo e ajusta os centavos. A divisão organiza as partes; não registra recebimento.</p>
            <button class="button secondary">Criar divisão</button>
        </form>
    </details>
    @endif
    @endif
    @if(!empty($paymentSummary['shares']))
    <div class="operation-shares">
        @foreach($paymentSummary['shares'] as $share)<div><strong>{{ $share['person_name'] }}</strong><span>Saldo R$ {{ number_format($share['balance'],2,',','.') }}</span></div>@endforeach
    </div>
    @if(\Illuminate\Support\Facades\Route::has('restaurant.divisions.cancel'))
    @foreach(collect($paymentSummary['shares'])->groupBy('division_key') as $divisionKey=>$parts)
    <details class="operation-line-actions"><summary>Cancelar esta divisão entre pessoas</summary><form method="post" action="{{ route('restaurant.divisions.cancel',['company'=>$tenant->slug,'order'=>$order->id,'division'=>$divisionKey]) }}">@csrf<input type="hidden" name="version" value="{{ $order->version }}"><div class="field"><label for="division-reason-{{ $loop->index }}">Motivo</label><input id="division-reason-{{ $loop->index }}" name="reason" minlength="3" maxlength="1000" required></div><p class="hint">Os valores já recebidos continuam registrados. Esta ação cancela a organização por pessoas.</p><button class="button secondary">Cancelar divisão</button></form></details>
    @endforeach
    @endif
    @endif
    @foreach($paymentSummary['payments'] as $receipt)
    <article class="operation-approval">
        <div class="operation-line-top"><strong>R$ {{ number_format($receipt->amount,2,',','.') }}</strong><span class="operation-badge">{{ $receipt->refunded_at?'Estornado':'Recebido' }}</span></div>
        <p class="hint">{{ $receipt->payment_snapshot['label']??'Recebimento' }}@if($receipt->share) · {{ $receipt->share->person_name }}@endif · {{ $receipt->created_at?->format('d/m H:i') }}</p>
        @foreach($receipt->allocations as $allocation)<small>{{ number_format($allocation->quantity,3,',','.') }} × {{ $allocation->description }}</small>@endforeach
        @if(bccomp((string)($receipt->payment_snapshot['change']??'0'),'0',2)>0)<p class="hint">Troco: R$ {{ number_format($receipt->payment_snapshot['change'],2,',','.') }}</p>@endif
        @if(!$receipt->refunded_at && $currentUser->allows('restaurant.refund') && \Illuminate\Support\Facades\Route::has('restaurant.payments.refund'))
        <details class="operation-line-actions"><summary>Estornar recebimento</summary><form method="post" action="{{ route('restaurant.payments.refund',['company'=>$tenant->slug,'order'=>$order->id,'payment'=>$receipt->id]) }}">@csrf<input type="hidden" name="version" value="{{ $order->version }}"><input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}"><div class="field"><label for="refund-reason-{{ $receipt->id }}">Motivo do estorno</label><input id="refund-reason-{{ $receipt->id }}" name="reason" minlength="3" maxlength="1000" required></div><p class="hint">As taxas bancárias já ocorridas permanecem como despesa. Confirme a devolução pelo meio usado no pagamento.</p><label class="checkbox-line"><input type="checkbox" name="refund_confirmed" value="1" required> Confirmei a devolução do valor ao cliente.</label><button class="button secondary">Registrar estorno</button></form></details>
        @endif
    </article>
    @endforeach
</section>
@once
@push('scripts')<script src="/operation-restaurant-payments.js?v={{ filemtime(public_path('operation-restaurant-payments.js')) }}" defer></script>@endpush
@endonce
@endif
