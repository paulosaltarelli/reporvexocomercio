<div class="card-fee-fields {{ ($splitCardFields??false)?'split-card-fee':'single-card-fee' }}" hidden>
<div class="form-grid">
<div class="field"><label>Bandeira do cartão</label><select data-field="card_brand" @unless($splitCardFields??false) name="card_brand" @endunless><option value="">Selecione a bandeira</option>@foreach(app(\App\Services\CardFees::class)->options() as $brandId=>$brand)<option value="{{ $brandId }}" data-type="{{ $brand['type'] }}" @selected(old('card_brand')===$brandId)>{{ $brand['name'] }} · {{ $brand['type']==='credit'?'Crédito':'Débito' }}</option>@endforeach</select></div>
<div class="field"><label>Parcelas no cartão</label><select data-field="card_installments" @unless($splitCardFields??false) name="card_installments" @endunless>@for($n=1;$n<=24;$n++)<option value="{{ $n }}" @selected((int)old('card_installments',1)===$n)>{{ $n }}x</option>@endfor</select></div>
<div class="field"><label>Quem assume a taxa?</label><select data-field="fee_policy" @unless($splitCardFields??false) name="fee_policy" @endunless><option value="absorb" @selected(old('fee_policy')==='absorb')>A loja assume</option><option value="pass" @selected(old('fee_policy')==='pass')>Repassar ao cliente</option></select></div>
</div><p class="card-fee-preview hint" role="status" aria-live="polite"></p>
<input type="hidden" data-field="rate_seen" @unless($splitCardFields??false) name="rate_seen" value="{{ old('rate_seen') }}" @endunless><input type="hidden" data-field="charged_seen" @unless($splitCardFields??false) name="charged_seen" value="{{ old('charged_seen') }}" @endunless>
<label class="checkbox-line card-fee-review"><input type="checkbox" data-field="fee_reviewed" value="1" @unless($splitCardFields??false) name="fee_reviewed" @checked(old('fee_reviewed')) @endunless> Conferi o acréscimo e informei o total ao cliente.</label>
</div>
@once
@push('scripts')<script>window.vendraCardBrands=@json(app(\App\Services\CardFees::class)->options());</script><script src="/card-fees.js?v={{ filemtime(public_path('card-fees.js')) }}"></script>@endpush
@endonce
