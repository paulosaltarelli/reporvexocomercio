@if(\App\Fiscal\WorkOrderFiscalSplit::eligibleOrigin($sale))
@if($receipts->count() > 1)
<p>Informe quanto de cada recebimento corresponde às mercadorias. O saldo de cada meio fica atribuído ao serviço.</p>
@foreach($receipts as $method=>$received)
<label>{{ \App\Services\PaymentMethods::TYPES[$method] ?? $method }} · recebido R$ {{ number_format($received,2,',','.') }}
<input type="number" name="goods_payments[{{ $method }}]" min="0" max="{{ $received }}" step="0.01" required value="{{ old('goods_payments.'.$method) }}"></label>
@endforeach
@endif
<label><input type="checkbox" name="segregate_work_order" value="1" required> Conferi a DPS do serviço. Autorizo incluir apenas as mercadorias desta OS e o valor correspondente no documento de homologação.</label>
@endif
