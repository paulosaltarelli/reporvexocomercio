@once
@if($currentUser->allows('customers.manage')||$currentUser->allows('products.manage'))
<p id="quick-create-feedback" class="hint" role="status" aria-live="polite"></p>
<dialog id="quick-create-dialog" class="quick-create-dialog" aria-labelledby="quick-create-title" data-endpoint="{{ route('quick.create',['company'=>$tenant->slug,'kind'=>'__kind__']) }}" data-inventory="{{ $operationConfig['inventory']?'1':'0' }}">
<form id="quick-create-form" method="post">
@csrf<input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<div class="section-heading"><h2 id="quick-create-title">Cadastro rápido</h2><button type="button" class="text-button quick-cancel" aria-label="Fechar cadastro rápido" title="Fechar"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div>
<p class="hint">Preencha os dados essenciais para usar no atendimento.</p>
<div id="quick-create-errors" class="notice error" role="alert" hidden></div>
<div class="field"><label id="quick-name-label" for="quick-name">Nome</label><input id="quick-name" name="name" maxlength="180" required></div>
<fieldset data-quick-section="customer"><legend>Contato do cliente · opcional</legend><div class="form-grid"><div class="field full"><label for="quick-document">CPF / CNPJ</label><div class="control-with-action">
    <input id="quick-document" name="document" maxlength="20" inputmode="numeric">
    @if($currentUser->allows('customers.manage'))
    <button type="button" class="button secondary" data-registry-url="{{ route('customer.cnpj', ['company' => $tenant->slug]) }}" aria-describedby="quick-registry-status" hidden>Buscar CNPJ</button>
    @endif
</div><small class="hint" id="quick-registry-status" role="status"></small></div><div class="field"><label for="quick-phone">Telefone</label><input id="quick-phone" name="phone" type="tel" maxlength="30"></div><div class="field"><label for="quick-email">E-mail</label><input id="quick-email" name="email" type="email" maxlength="180"></div></div></fieldset>
@if($currentUser->allows('products.manage'))
<fieldset data-quick-section="item"><legend>Dados do item</legend><div class="form-grid"><div class="field"><label for="quick-group">Grupo</label><select id="quick-group" name="product_group_id" required><option value="">Selecione</option>@foreach(\App\Models\ProductGroup::orderBy('name')->get() as $group)<option value="{{ $group->id }}">{{ $group->name }}</option>@endforeach</select><small class="hint">As regras de preço, desconto e comissão do grupo serão respeitadas.</small></div><div class="field"><label for="quick-price">Preço de venda (R$)</label><input id="quick-price" name="sale_price" type="number" min="0" max="999999999999.99" step="0.01" required></div><div class="field"><label for="quick-unit">Unidade</label><select id="quick-unit" name="unit">@foreach(['UN','KG','M','L','CX','PC','PAR'] as $unit)<option>{{ $unit }}</option>@endforeach</select></div><div class="field"><label for="quick-sku">Código / SKU · opcional</label><input id="quick-sku" name="sku" maxlength="80"><small class="hint">Se vazio, o sistema gera um código.</small></div><div class="field"><label for="quick-barcode">Código de barras · opcional</label><input id="quick-barcode" name="barcode" maxlength="80"></div></div>
<div class="quick-stock-choice"><input type="hidden" name="controls_stock" value="0"><label class="checkbox-line"><input type="checkbox" name="controls_stock" value="1" id="quick-stock"> Controlar estoque deste produto</label></div>
@if($currentUser->allows('stock.manage'))<fieldset data-quick-section="stock"><legend>Saldo inicial · opcional</legend><div class="form-grid"><div class="field"><label for="quick-quantity">Quantidade em estoque</label><input id="quick-quantity" name="initial_quantity" type="number" min="0" max="999999999" step="0.001" value="0"></div><div class="field"><label for="quick-cost">Custo unitário do saldo inicial (R$)</label><input id="quick-cost" name="initial_cost" type="number" min="0" max="999999999.9999" step="0.0001"></div></div><p class="hint">O saldo informado gera uma entrada de estoque identificada. Informe somente a quantidade disponível de fato.</p></fieldset>@endif
<p class="hint">Sem saldo inicial, um produto controlado começa com estoque zero. Dados fiscais e complementares podem ser preenchidos no cadastro completo.</p>
</fieldset>@endif
<div class="actions"><button type="submit" class="primary" id="quick-create-save">Salvar e usar</button><button type="button" class="button secondary quick-cancel">Cancelar</button></div>
</form></dialog>
@push('scripts')<script src="/quick-create.js?v={{ filemtime(public_path('quick-create.js')) }}"></script>@if($currentUser->allows('customers.manage'))
<script defer src="/customer-registry.js?v={{ filemtime(public_path('customer-registry.js')) }}"></script>
@endif
@endpush
@endif
@endonce
