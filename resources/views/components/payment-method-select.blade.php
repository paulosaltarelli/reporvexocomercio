@php($paymentOptions=app(\App\Services\PaymentMethods::class)->options())
@php($paymentSelected=old('payment_option',old('payment_method',$paymentDefault??'cash')))
<select id="{{ $paymentSelectId??'payment-option' }}" name="payment_option" required>
<option value="" @selected(!isset($paymentOptions[$paymentSelected])) disabled>Selecione uma forma de pagamento</option>
@foreach($paymentOptions as $methodId=>$method)<option value="{{ $methodId }}" data-type="{{ $method['type'] }}" @selected($paymentSelected===$methodId)>{{ $method['label'] }}</option>@endforeach
</select><input type="hidden" name="payment_method" value="{{ $paymentOptions[$paymentSelected]['type']??'cash' }}">
