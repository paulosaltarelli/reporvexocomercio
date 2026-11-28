<form method="post" action="{{ route('fiscal.emit',['company'=>$tenant->slug,'id'=>$sale->id]) }}">@csrf
@if($returnToSale ?? false)<input type="hidden" name="return_to_sale" value="1">@endif
<div class="form-grid"><div class="field"><label for="destination_state">UF de destino desta venda</label><input id="destination_state" name="destination_state" maxlength="2" value="{{ old('destination_state', ($returnToSale ?? false) ? ($companyProfile?->state ?? '') : '') }}" required></div><div class="field"><label for="presence">Presença do comprador</label><select id="presence" name="presence"><option value="1">Presencial</option></select></div></div>
@include('fiscal.work-order-consent')
@if($hasPixReceipt)
<div class="field"><label for="pix_mode">Modalidade do Pix recebido</label><select id="pix_mode" name="pix_mode" required><option value="">Confirme como o Pix foi recebido</option><option value="dynamic" @selected(old('pix_mode')==='dynamic')>Pix dinâmico · cobrança específica para esta venda</option><option value="static" @selected(old('pix_mode')==='static')>Pix estático · chave ou QR Code reutilizável</option></select><small class="hint">Informe a modalidade real do recebimento. A forma de pagamento “Pix” sozinha não determina esta informação.</small></div>
@endif
<label class="checkbox-line"><input type="checkbox" name="consumer_final" value="1" required> Confirmo que é uma venda a consumidor final.</label>
<label class="checkbox-line"><input type="checkbox" name="not_taxpayer" value="1" required> Destinatário não contribuinte de ICMS nesta operação.</label>
<label class="checkbox-line"><input type="checkbox" name="homologation_consent" value="1" required> Autorizo transmitir os dados desta empresa e venda à SEFAZ para teste em homologação, sem valor fiscal.</label>
<button class="primary">Solicitar emissão em homologação</button></form>
