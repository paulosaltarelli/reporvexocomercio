<div class="modifier-option panel" style="margin:12px 0">
    <input type="hidden" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][id]" value="{{ $option['id']??'' }}">
    <div class="form-grid">
        <div class="field"><label>Nome do adicional</label><input name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][name]" maxlength="100" value="{{ $option['name']??'' }}" required></div>
        <div class="field"><label>Preço adicional por unidade (R$)</label><input type="number" min="0" max="999999999.99" step="0.01" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][price]" value="{{ $option['price']??'0.00' }}" required></div>
        <div class="field"><input type="hidden" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][active]" value="0"><label><input type="checkbox" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][active]" value="1" @checked($option['active']??true)> Disponível no cardápio</label></div>
    </div>
    <details class="disclosure compact"><summary>Consumo de ingrediente deste adicional (opcional)</summary><div class="form-grid recipe-row">
        <div class="field"><label>Ingrediente</label><select name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][ingredient_product_id]" data-recipe-product><option value="">Sem consumo de ingrediente</option>@foreach($ingredients as $ingredient)<option value="{{ $ingredient->id }}" data-units="{{ json_encode(\App\Services\MeasureUnits::options($ingredient,$catalogConfiguration,'sale')) }}" @selected((string)($option['ingredient_product_id']??'')===(string)$ingredient->id)>{{ $ingredient->name }} · {{ $ingredient->unit }}{{ $ingredient->active?'':' · inativo' }}</option>@endforeach</select></div>
        <div class="field"><label>Quantidade por adicional</label><input type="number" min="0.001" max="999999" step="0.001" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][ingredient_quantity]" value="{{ $option['ingredient_quantity']??'' }}"></div>
        <div class="field"><label>Unidade do ingrediente</label><select name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][ingredient_measure_unit]" data-recipe-unit data-selected="{{ $option['ingredient_measure_unit']??$option['ingredient_unit']??'' }}"></select><input type="hidden" name="groups[{{ $groupIndex }}][options][{{ $optionIndex }}][ingredient_factor_seen]" data-recipe-factor></div>
    </div></details>
</div>
