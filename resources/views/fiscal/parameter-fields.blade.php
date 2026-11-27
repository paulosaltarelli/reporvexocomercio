<div class="form-grid" data-tax-parameters>
<div class="field">
    <label for="tax-model">Finalidade da regra</label>
    <select name="tax_model" id="tax-model">
        <option value="legacy" @selected(old('tax_model', $p['tax_model'] ?? 'legacy') === 'legacy')>Homologação de NF-e e NFC-e com PIS/Cofins</option>
        <option value="simples_nfce_2026" @selected(old('tax_model', $p['tax_model'] ?? '') === 'simples_nfce_2026')>NFC-e de revenda interna em 2026 · Simples Nacional (CRT 1)</option>
        <option value="regular_resale_2027" @selected(old('tax_model', $p['tax_model'] ?? '') === 'regular_resale_2027')>Prévia de revenda em 2027 · regime regular</option>
    </select>
    <small class="hint" data-reform-help>Na prévia de revenda interna, informe a vigência em 2027, ICMS 00, IBS/CBS integral 000/000001, IBS de 0,05% estadual e 0,05% municipal e a alíquota efetiva da CBS validada para a operação. O limite da vigência é 31/12/2027.</small>
    <small class="hint" data-simples-help hidden>Use para mercadoria adquirida de terceiros na venda presencial a consumidor final. Confirme CFOP 5102, CSOSN 102 e PIS/Cofins 99 com base e alíquotas zeradas. A vigência termina em 31/12/2026.</small>
</div>
<div class="field"><label for="tax-valid-from">Início da vigência <span data-optional-start>(opcional)</span></label><input type="date" id="tax-valid-from" name="valid_from" value="{{ old('valid_from', $p['valid_from'] ?? '') }}"></div>
<div class="field"><label for="tax-valid-until">Último dia de vigência (opcional)</label><input type="date" id="tax-valid-until" name="valid_until" value="{{ old('valid_until', $p['valid_until'] ?? '') }}"></div>
@foreach(['cfop'=>'CFOP interno (escopo inicial: 5102)','icms_code'=>'CST/CSOSN ICMS','icms_rate'=>'Alíquota ICMS (%) quando tributado','pis_code'=>'CST PIS','pis_rate'=>'Alíquota PIS (%) quando tributado','cofins_code'=>'CST COFINS','cofins_rate'=>'Alíquota COFINS (%) quando tributado','transparency_rate'=>'Percentual total aproximado de tributos (%)','rtc_cst'=>'CST IBS/CBS (integral: 000)','rtc_class'=>'Classificação tributária IBS/CBS (integral: 000001)','ibs_uf_rate'=>'Alíquota IBS estadual (%)','ibs_municipal_rate'=>'Alíquota IBS municipal (%)','cbs_rate'=>'Alíquota CBS (%)','rtc_reason'=>'Fundamento de não incidência de IBS/CBS nesta operação'] as $field=>$label)
<div class="field" @if(in_array($field,['pis_code','pis_rate','cofins_code','cofins_rate','transparency_rate','rtc_reason'],true)) data-legacy-field @endif><label for="tax_{{ $field }}">{{ $label }}</label><input id="tax_{{ $field }}" name="{{ $field }}" @if(str_ends_with($field,'_rate')) type="number" min="0" max="100" step="0.0001" @endif value="{{ old($field,$p[$field]??'') }}" maxlength="255" @if($field==='cfop') pattern="5102" @elseif($field==='rtc_cst') pattern="000" @elseif($field==='rtc_class') pattern="000001" @endif></div>@endforeach
<div class="field"><label for="rtc_mode">Tratamento IBS/CBS validado</label><select id="rtc_mode" name="rtc_mode" required><option value="">Selecione</option><option value="standard" @selected(old('rtc_mode',$p['rtc_mode']??'')==='standard')>Tributação integral</option><option value="not_applicable" @selected(old('rtc_mode',$p['rtc_mode']??'')==='not_applicable')>Não aplicável · permite revisão, bloqueia emissão</option></select></div>
<div class="field" data-legacy-field><label for="pis_cofins_base_mode">Base de PIS/COFINS com ICMS tributado</label><select id="pis_cofins_base_mode" name="pis_cofins_base_mode"><option value="">Selecione quando aplicável</option><option value="exclude_icms" @selected(old('pis_cofins_base_mode',$p['pis_cofins_base_mode']??'')==='exclude_icms')>Excluir o ICMS da base de PIS/COFINS</option></select><small class="hint">Confirmação obrigatória se ICMS CST 00 e PIS ou COFINS CST 01/02. A base integral não é aceita nesse caso.</small></div>
<div class="field"><label for="accountant_reference">Responsável e referência da validação contábil</label><input id="accountant_reference" name="accountant_reference" value="{{ old('accountant_reference',$accountantReference??null) }}" required maxlength="180"></div>
</div>
@push('scripts')
<script defer src="/fiscal-tax-model.js?v={{ filemtime(public_path('fiscal-tax-model.js')) }}"></script>
@endpush
