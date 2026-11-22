@extends('layout')
@section('title', 'Prévia de revenda em 2027')
@section('subtitle', $product->name)
@section('content')
<p><a href="{{ route('fiscal.product', ['company' => $tenant->slug, 'id' => $product->id]) }}">Ver regra fiscal do produto</a></p>
<form class="panel" method="post" action="{{ route('fiscal.profiles.reform-preview', ['company' => $tenant->slug, 'id' => $product->id]) }}">
    @csrf
    <h2>Simular um item</h2>
    <p>Revenda interna no lucro real ou presumido, com ICMS CST 00 e tributação integral de IBS e CBS. As alíquotas vêm da regra fiscal válida na data escolhida.</p>
    <div class="field">
        <label for="preview-profile">Regra para esta simulação</label>
        <select id="preview-profile" name="profile_id">
            <option value="">Usar a regra vinculada ao produto</option>
            @foreach($profiles as $profile)
                <option value="{{ $profile->id }}" @selected($values['profile_id'] === (string) $profile->id)>{{ $profile->name }}</option>
            @endforeach
        </select>
        <small class="hint">Escolha outro perfil para testar 2027 mantendo o vínculo atual do produto.</small>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="reference-date">Data da operação</label>
            <input id="reference-date" name="reference_date" type="date" min="2027-01-01" max="2027-12-31" required value="{{ $values['reference_date'] ?? '2027-01-01' }}">
        </div>
        <div class="field">
            <label for="item-amount">Valor do item antes de IBS e CBS (R$)</label>
            <input id="item-amount" name="item_amount" type="number" min="0.01" step="0.01" required value="{{ $values['item_amount'] }}">
            <small class="hint">Use o valor após o desconto incondicional, já com o ICMS incluído.</small>
        </div>
        <div class="field">
            <label for="icms-base">Base integral de ICMS do item (R$)</label>
            <input id="icms-base" name="icms_base" type="number" min="0.01" step="0.01" required value="{{ $values['icms_base'] }}">
            <small class="hint">Informe a base validada pelo contador conforme a UF. A prévia calcula o ICMS com a alíquota do perfil.</small>
        </div>
    </div>
    <label class="checkbox-line"><input type="checkbox" name="confirm_scope" value="1" required @checked($values['confirm_scope'] === '1')> A operação é uma revenda interna CFOP 5102 com base integral, sem redução, frete, IPI, Imposto Seletivo, FCP, outros encargos, substituição tributária ou regime especial.</label>
    <div class="actions"><button class="primary">Calcular prévia</button></div>
</form>

@if($result)
<section class="panel">
    <h2>Composição do item em {{ $result['reference_date'] }}</h2>
    <p>{{ $result['rule_name'] }} · Referência contábil {{ $result['accountant_reference'] }}</p>
    <div class="scroll"><table><thead><tr><th>Componente</th><th>Alíquota</th><th>Valor</th></tr></thead><tbody>
        <tr><th>Valor antes de IBS e CBS</th><td></td><td>R$ {{ number_format($result['item_amount'], 2, ',', '.') }}</td></tr>
        <tr><th>ICMS sobre R$ {{ number_format($result['icms_base'], 2, ',', '.') }}, excluído da base de IBS e CBS</th><td>{{ rtrim(rtrim(number_format($result['icms_rate'], 4, ',', ''), '0'), ',') }}%</td><td>− R$ {{ number_format($result['icms_amount'], 2, ',', '.') }}</td></tr>
        <tr><th>Base de IBS e CBS</th><td></td><td>R$ {{ number_format($result['base'], 2, ',', '.') }}</td></tr>
        @foreach(['ibs_uf_rate' => 'IBS estadual', 'ibs_municipal_rate' => 'IBS municipal', 'cbs_rate' => 'CBS efetiva'] as $key => $label)
        <tr><th>{{ $label }}</th><td>{{ rtrim(rtrim(number_format($result['tax'][$key]['rate'], 4, ',', ''), '0'), ',') }}%</td><td>R$ {{ number_format($result['tax'][$key]['value'], 2, ',', '.') }}</td></tr>
        @endforeach
    </tbody><tfoot>
        <tr><th colspan="2">Total do item com IBS e CBS</th><td><strong>R$ {{ number_format($result['total'], 2, ',', '.') }}</strong></td></tr>
    </tfoot></table></div>
    <p class="hint">Em 2027, a dedução do ICMS se aplica à base de IBS e CBS. O total do item conserva o ICMS e soma o IBS e a CBS calculados por fora. PIS e Cofins não entram neste cálculo.</p>
    <p>Simulação sem valor fiscal. <a href="https://www.planalto.gov.br/ccivil_03/leis/lcp/lcp214.htm" target="_blank" rel="noopener">LC 214/2025, arts. 12, 344 e 347</a></p>
</section>
@endif
@endsection
