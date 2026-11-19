@php
    $prefix = $prefix ?? '';
    $customerFields = [
        'name' => ['Nome / razão social', 'text', 180],
        'trade_name' => ['Nome fantasia', 'text', 180],
        'legal_nature' => ['Tipo de empresa / natureza jurídica', 'text', 180],
        'opened_on' => ['Data de abertura', 'date', 10],
        'registration_status' => ['Situação cadastral', 'text', 60],
        'primary_cnae' => ['CNAE principal', 'text', 12],
        'fiscal_state_registration' => ['Inscrição estadual (IE)', 'text', 22],
        'email' => ['E-mail', 'email', 180],
        'phone' => ['Telefone', 'tel', 30],
    ];
    $addressFields = [
        'fiscal_postal_code' => ['CEP', 'text', 9],
        'fiscal_street' => ['Logradouro', 'text', 60],
        'fiscal_number' => ['Número', 'text', 60],
        'fiscal_complement' => ['Complemento', 'text', 60],
        'fiscal_district' => ['Bairro', 'text', 60],
        'fiscal_city' => ['Cidade', 'text', 60],
        'fiscal_state' => ['UF', 'text', 2],
        'fiscal_municipality_code' => ['Código IBGE do município', 'text', 7],
    ];
@endphp
<div class="form-grid">
<div class="field {{ $prefix ? 'full' : '' }}">
    <label for="{{ $prefix }}document">CPF / CNPJ</label>
    <div class="control-with-action">
        <input id="{{ $prefix }}document" name="document" type="text" maxlength="20" value="{{ $prefix ? '' : old('document', $editing?->document) }}">
        <button type="button" class="button secondary" data-registry-url="{{ route('customer.cnpj', ['company' => $tenant->slug]) }}" hidden>Buscar CNPJ</button>
    </div>
    <small class="hint" id="{{ $prefix }}customer-registry-status" role="status"></small>
</div>
@foreach($customerFields as $field => [$label, $type, $limit])
    @include('components.customers.registry-input')
@endforeach
</div>
<h3>Endereço</h3>
<div class="form-grid">
    @foreach($addressFields as $field => [$label, $type, $limit])
        @include('components.customers.registry-input')
    @endforeach
    <div class="field full">
        <label for="{{ $prefix }}address">Endereço livre ou referência</label>
        <textarea id="{{ $prefix }}address" name="address" maxlength="1000">{{ $prefix ? '' : old('address', $editing?->address) }}</textarea>
    </div>
</div>
@push('scripts')
<script src="/customer-registry.js?v={{ filemtime(public_path('customer-registry.js')) }}" defer></script>
@endpush
