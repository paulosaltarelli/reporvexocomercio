<div class="field">
    <label for="salesperson_id">Vendedor creditado</label>
    @if($currentUser->allows('sales.seller'))
        <select id="salesperson_id" name="salesperson_id" required>
            <option value="">Selecione o vendedor</option>
            @foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)old('salesperson_id',$selectedSalespersonId??$currentSalesperson?->id)===(string)$seller->id)>{{ $seller->name }}</option>@endforeach
        </select>
        <small class="hint">A venda será creditada a este vendedor.</small>
        @if($sellers->isEmpty())<p class="hint"><a href="{{ route('sellers',['company'=>$tenant->slug]) }}">Cadastre um vendedor ativo</a> para continuar.</p>@endif
    @elseif($currentSalesperson && $currentSalesperson->active)
        <input type="hidden" name="salesperson_id" value="{{ $currentSalesperson->id }}">
        <input id="salesperson_id" value="{{ $currentSalesperson->name }}" readonly aria-describedby="seller-note">
        <small class="hint" id="seller-note">Vendedor vinculado ao seu acesso.</small>
    @else
        <div class="notice error" role="alert">Seu acesso não tem um perfil de vendedor ativo. Peça ao administrador para conferir o vínculo antes de registrar a venda ou o orçamento.</div>
    @endif
</div>
