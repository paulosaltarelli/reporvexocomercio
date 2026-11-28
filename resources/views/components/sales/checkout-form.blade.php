@php($checkoutPaid=(string)(int)old('paid','1'))
@php($checkoutSellerId=old('salesperson_id',$selectedSalespersonId??$currentSalesperson?->id))
@php($checkoutSeller=$sellers->firstWhere('id',(int)$checkoutSellerId)??$currentSalesperson)
@php($checkoutBlocked=!$currentUser->allows('sales.seller') && (!$currentSalesperson || !$currentSalesperson->active))
<form id="checkout-form" class="pos-checkout" data-stock-policy="{{ $operationConfig['inventory'] ? $operationConfig['negative_stock'] : 'allow' }}" method="post" action="{{ route('sales',['company'=>$tenant->slug]) }}">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key',\Illuminate\Support\Str::uuid()) }}">
    <div class="pos-grid">
        <div class="pos-cart">
            @include('components.sales.entry-items',['itemsHeading'=>'Itens da venda','isPos'=>true])
        </div>
        <section class="panel pos-stage-summary" aria-labelledby="pos-stage-title" hidden>
            <div class="pos-stage-amount">
                <h2 id="pos-stage-title">Total a pagar</h2>
                <strong data-pos-stage-total aria-live="polite">R$ 0,00</strong>
            </div>
            <div class="pos-stage-controls">
                <p class="hint" data-pos-fiscal-summary></p>
                <button type="button" class="primary" data-pos-payment @disabled($checkoutBlocked)>Pagamento <kbd>F9</kbd></button>
                <button type="button" class="button secondary" data-pos-start>Iniciar venda</button>
            </div>
        </section>
        <dialog class="pos-stage-dialog" id="pos-payment-dialog" aria-labelledby="pos-payment-title"></dialog>
        <dialog class="pos-stage-dialog panel" id="pos-start-dialog" aria-labelledby="pos-start-title">
            <h2 id="pos-start-title">Iniciar venda</h2>
            <div class="field" data-pos-start-fiscal hidden>
                <label for="pos-start-cpf">CPF na nota (opcional)</label>
                <input id="pos-start-cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00">
                <p class="hint">Venda presencial no estado, a consumidor final não contribuinte do ICMS.</p>
            </div>
            <div class="actions">
                <button type="button" class="primary" data-pos-start-confirm>Continuar <kbd>Enter</kbd></button>
                <button type="button" class="button secondary" data-pos-start-without-note hidden>Continuar sem NFC-e <kbd>Esc</kbd></button>
            </div>
        </dialog>
        <section class="panel pos-payment" data-pos-payment-panel aria-labelledby="pos-payment-title">
            <span class="eyebrow">VENDA ATUAL</span><h2 id="pos-payment-title">Resumo e pagamento</h2>
            <div class="pos-summary"><div><span>Subtotal</span><strong id="pos-gross-total">R$ 0,00</strong></div><div><span>Descontos</span><strong id="pos-discount-total">R$ 0,00</strong></div></div>
            <div class="pos-grand-total"><span>Total a pagar</span><strong id="pos-net-total" aria-live="polite">R$ 0,00</strong></div>
            <div class="single-payment-fields"><div class="field"><label for="pos-payment-method">Forma de pagamento</label>@include('components.payment-method-select',['paymentSelectId'=>'pos-payment-method','paymentDefault'=>'cash'])</div>
            <div class="field"><label for="pos-paid">Recebimento</label><select id="pos-paid" name="paid"><option value="1" @selected($checkoutPaid==='1')>Recebido agora</option><option value="0" @selected($checkoutPaid==='0')>A receber</option></select></div>
            <div id="pos-credit-fields" @if($checkoutPaid!=='0') hidden @endif><div class="field"><label for="pos-due-date">Primeiro vencimento</label><input id="pos-due-date" type="date" name="due_date" value="{{ old('due_date') }}"></div><div class="field"><label for="pos-installments">Parcelas</label><input id="pos-installments" name="installments" type="number" min="1" max="24" step="1" value="{{ old('installments',1) }}" required></div></div>
            <div id="pos-cash-fields"><div class="field"><label for="pos-cash-received">Valor entregue (opcional)</label><input id="pos-cash-received" type="number" min="0" max="999999999999.99" step="0.01" inputmode="decimal" placeholder="0,00"></div><div class="pos-change"><span>Troco</span><strong id="pos-change">—</strong></div></div>
            @include('components.sales.card-fees')
            </div>
            @include('components.sales.split-payments')
            <p class="card-sale-total notice" role="status" hidden></p>
            <details class="pos-optionals" @if(old('customer_id',$selectedCustomerId??null)||$errors->hasAny(['customer_id','salesperson_id','notes'])) open @endif><summary>Cliente e vendedor <span>opcional</span></summary><div class="pos-optionals-content">
                @include('components.sales.customer-field',['customerFieldId'=>'pos-customer','customerEmptyLabel'=>'Consumidor final'])
                @include('components.sales.seller-field')
                <div class="field"><label for="pos-notes">Observações</label><input id="pos-notes" name="notes" maxlength="1000" value="{{ old('notes') }}"></div>
            </div></details>
            <p class="pos-customer-summary">{{ $customers->firstWhere('id',(int)old('customer_id',$selectedCustomerId??0))?->name??'Consumidor final' }} · {{ $checkoutSeller?->name??'Selecione um vendedor' }}</p>
            @if($nfceAvailable && $operationConfig['nfce'] && $currentUser->allows('fiscal.manage') && !isset(request()->attributes->get('restrictedModules', [])['fiscal']))
                <div class="pos-optionals">
                    <div class="field" data-pos-inline-cpf>
                        <label for="checkout-nfce-cpf">CPF na nota (opcional) <kbd>F8</kbd></label>
                        <input id="checkout-nfce-cpf" name="consumer_cpf" inputmode="numeric" autocomplete="off" maxlength="14" value="{{ old('consumer_cpf') }}" placeholder="000.000.000-00">
                    </div>
                    <label class="checkbox-line" data-pos-inline-issue>
                        <input type="checkbox" name="issue_nfce" value="1" @checked(old('issue_nfce'))>
                        Enviar NFC-e em homologação ao registrar a venda
                    </label>
                    <p class="hint" data-pos-inline-issue>Marque para venda presencial dentro do estado a consumidor final não contribuinte do ICMS. A nota de teste é enviada à SEFAZ.</p>
                    @php($defaultPix = config('fiscal.nfce_pix_defaults.'.$tenant->slug))
                    <details class="disclosure pos-optionals" data-pos-fiscal-pix @if(!$defaultPix) open @endif>
                        <summary>{{ $defaultPix === 'static' ? 'Pix estático · alterar modalidade' : 'Modalidade do Pix recebido' }}</summary>
                        <div class="disclosure-content field">
                            <label for="checkout-nfce-pix">Modalidade do Pix recebido</label>
                            <select id="checkout-nfce-pix" name="nfce_pix_mode">
                                <option value="">Selecione se recebeu por Pix</option>
                                <option value="static" @selected(old('nfce_pix_mode', $defaultPix) === 'static')>Pix estático</option>
                                <option value="dynamic" @selected(old('nfce_pix_mode', $defaultPix) === 'dynamic')>Pix dinâmico</option>
                            </select>
                        </div>
                    </details>
                </div>
            @endif
            <p class="notice error" data-pos-fiscal-payment-error role="status" hidden></p>
            <button type="button" class="button secondary" data-pos-back hidden>Voltar aos itens <kbd>Esc</kbd></button>
            <button id="checkout-submit" class="primary pos-finalize" @disabled($checkoutBlocked)>Registrar venda e recebimento <kbd>F9</kbd></button>
            <p class="hint pos-payment-note">Confira o recebimento antes de finalizar. Pix e cartões são registrados manualmente.</p>
        </section>
    </div>
</form>
@include('components.quick-create')
@push('scripts')<link rel="stylesheet" href="/pos-stages.css?v={{ filemtime(public_path('pos-stages.css')) }}"><script src="/pos.js?v={{ filemtime(public_path('pos.js')) }}"></script>@endpush
