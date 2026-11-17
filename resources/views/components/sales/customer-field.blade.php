@php($customerFieldId=$customerFieldId??'customer-id')
<div class="field customer-field">
<label for="{{ $customerFieldId }}">Cliente</label>
<div class="control-with-action">
<select id="{{ $customerFieldId }}" name="customer_id"><option value="">{{ $customerEmptyLabel??'Não informado' }}</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected((string)old('customer_id',$selectedCustomerId??null)===(string)$customer->id)>{{ $customer->name }}</option>@endforeach</select>
@if($currentUser->allows('customers.manage'))<button type="button" class="icon-button" data-quick-create="customers" aria-label="Novo cliente" title="Novo cliente" hidden><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true" focusable="false"><path d="M12 5v14M5 12h14"/></svg></button>@endif
</div></div>
