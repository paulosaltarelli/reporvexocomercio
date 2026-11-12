<div class="field">
    <label for="seller_id">Vendedor responsável</label>
    @if($currentUser->role==='admin')
        <select id="seller_id" name="seller_id" required>
            @foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)old('seller_id',$currentUser->id)===(string)$seller->id)>{{ $seller->name }}</option>@endforeach
        </select>
        <small class="hint">A venda e a comissão serão atribuídas a este vendedor. Quem registra a operação permanece identificado separadamente.</small>
    @else
        <input type="hidden" name="seller_id" value="{{ $currentUser->id }}">
        <input id="seller_id" value="{{ $currentUser->name }}" readonly aria-describedby="seller-note">
        <small class="hint" id="seller-note">Seu usuário será o vendedor responsável por este atendimento.</small>
    @endif
</div>
