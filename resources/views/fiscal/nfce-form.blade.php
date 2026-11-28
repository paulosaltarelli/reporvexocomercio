<form method="post" action="{{ route('fiscal.emit',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
@if($returnToSale ?? false)<input type="hidden" name="return_to_sale" value="1">@endif
<div class="form-grid"><div class="field"><label for="destination_state">UF de destino desta venda</label><input id="destination_state" name="destination_state" maxlength="2" value="{{ old('destination_state', ($returnToSale ?? false) ? ($companyProfile?->state ?? '') : '') }}" required></div><div class="field"><label for="presence">Presença do comprador</label><select id="presence" name="presence"><option value="1">Presencial</option></select></div></div>
<div class="field">
    <label for="nfce-consumer-cpf">CPF na nota (opcional) @if($returnToSale ?? false)<kbd>F8</kbd>@endif</label>
    <input id="nfce-consumer-cpf" name="consumer_cpf" inputmode="numeric" autocomplete="off" maxlength="14" value="{{ old('consumer_cpf') }}" placeholder="000.000.000-00">
    <small class="hint">Preencha se o cliente pedir CPF na nota. Em branco, o sistema usa o documento do cliente vinculado à venda, se houver.</small>
</div>
@include('fiscal.work-order-consent')
@if($hasPixReceipt)
@php($defaultPix = config('fiscal.nfce_pix_defaults.'.$tenant->slug))
<details class="disclosure" @if(!$defaultPix) open @endif>
    <summary>{{ $defaultPix === 'static' ? 'Pix estático · alterar modalidade' : 'Modalidade do Pix recebido' }}</summary>
    <div class="disclosure-content field">
        <label for="pix_mode">Modalidade do Pix recebido</label>
        <select id="pix_mode" name="pix_mode" required>
            <option value="">Selecione</option>
            <option value="static" @selected(old('pix_mode', $defaultPix) === 'static')>Pix estático</option>
            <option value="dynamic" @selected(old('pix_mode', $defaultPix) === 'dynamic')>Pix dinâmico</option>
        </select>
    </div>
</details>
@endif
<p class="hint">A solicitação envia a NFC-e de consumidor final não contribuinte à SEFAZ em homologação.</p>
<button class="primary">Solicitar emissão em homologação</button></form>
