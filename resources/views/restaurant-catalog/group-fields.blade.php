<section class="panel modifier-group" data-group-index="{{ $groupIndex }}">
    <input type="hidden" name="groups[{{ $groupIndex }}][id]" value="{{ $group['id']??'' }}">
    <div class="form-grid">
        <div class="field"><label>Nome do grupo</label><input name="groups[{{ $groupIndex }}][name]" maxlength="100" value="{{ $group['name']??'' }}" placeholder="Ex.: escolha o acompanhamento" required></div>
        <div class="field"><label>Mínimo de escolhas</label><input type="number" min="0" max="20" step="1" name="groups[{{ $groupIndex }}][min_choices]" value="{{ $group['min_choices']??0 }}" required></div>
        <div class="field"><label>Máximo de escolhas</label><input type="number" min="1" max="20" step="1" name="groups[{{ $groupIndex }}][max_choices]" value="{{ $group['max_choices']??1 }}" required></div>
        <div class="field"><input type="hidden" name="groups[{{ $groupIndex }}][active]" value="0"><label><input type="checkbox" name="groups[{{ $groupIndex }}][active]" value="1" @checked($group['active']??true)> Grupo disponível</label></div>
    </div>
    <p class="hint">Mínimo 1 ou mais torna a escolha obrigatória. Desative para retirar o grupo de novos pedidos.</p>
    <div data-modifier-options>@foreach($group['options']??[] as $optionIndex=>$option)@include('restaurant-catalog.option-fields')@endforeach</div>
    <button type="button" class="button secondary" data-add-modifier-option>+ Adicional</button>
</section>
