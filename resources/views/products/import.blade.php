@extends('layout')
@section('title', $preview === null ? 'Importar produtos' : 'Conferir importação de produtos')
@section('navigation-section', 'Cadastros')
@section('subtitle', $preview === null ? 'Cadastre vários produtos da loja a partir de uma planilha CSV.' : 'Confira os produtos da planilha antes de confirmar o cadastro.')
@section('actions')<a class="button secondary" href="{{ route('products', ['company' => $tenant->slug]) }}">Voltar aos produtos</a>@endsection
@push('styles')
<link rel="stylesheet" href="/product-import.css?v={{ filemtime(public_path('product-import.css')) }}">
@endpush
@section('content')
@if($result = session('product_import_result'))
    <section class="notice success" role="status">
        <strong>Importação concluída</strong>
        <p>{{ $result['created'] }} {{ $result['created'] === 1 ? 'produto cadastrado' : 'produtos cadastrados' }}.
            @if($result['skipped'] > 0){{ $result['skipped'] }} {{ $result['skipped'] === 1 ? 'produto já constava no catálogo' : 'produtos já constavam no catálogo' }}.@endif
        </p>
    </section>
@endif
@if($operationConfig['catalog_mode'] === 'services')
    <p class="notice">Esta loja está configurada para serviços. A importação de produtos fica disponível nos modos Produtos e Produtos e serviços.</p>
@elseif($preview === null)
    <section class="panel product-import-panel" aria-labelledby="product-import-heading">
        <div class="section-heading">
            <h2 id="product-import-heading">Planilha de produtos</h2>
            <a class="button secondary" href="{{ route('product.import.template', ['company' => $tenant->slug]) }}" download>Baixar modelo CSV</a>
        </div>
        <p>Mantenha o cabeçalho do modelo e preencha as linhas com os dados da loja. Informe SKU, nome, grupo, unidade e preço de venda. O código de barras é opcional.</p>
        <p class="hint">Use um grupo cadastrado na loja e a sigla da unidade, como UN ou KG. Formate o SKU e o código de barras como texto para manter os zeros à esquerda.</p>
        <form method="post" enctype="multipart/form-data" action="{{ route('product.import.preview', ['company' => $tenant->slug]) }}">
            @csrf
            <div class="field">
                <label for="products-csv-file">Arquivo CSV</label>
                <input id="products-csv-file" type="file" name="file" accept=".csv,text/csv" required aria-describedby="products-csv-format">
                <small class="hint" id="products-csv-format">UTF-8, até 200 produtos e 256 KiB. Use ponto e vírgula ou vírgula como separador.</small>
            </div>
            <div class="actions">
                <button type="submit" class="button">Conferir planilha</button>
                <a href="{{ route('catalog', ['company' => $tenant->slug, 'kind' => 'groups']) }}">Consultar grupos</a>
                <a href="{{ route('units', ['company' => $tenant->slug]) }}">Consultar unidades</a>
            </div>
        </form>
    </section>
@else
    @php
        $counts = $preview['counts'];
        $labels = ['new' => 'Novo', 'existing' => 'Já cadastrado', 'error' => 'Revisar'];
        $firstError = collect($preview['rows'])->firstWhere('status', 'error');
    @endphp
    <section class="panel product-import-preview" aria-labelledby="csv-summary">
        <h2 id="csv-summary">Resultado da conferência</h2>
        <dl class="product-import-counts">
            <div><dt>Novos</dt><dd>{{ $counts['new'] }}</dd></div>
            <div><dt>Já cadastrados</dt><dd>{{ $counts['existing'] }}</dd></div>
            <div><dt>Linhas com erro</dt><dd>{{ $counts['error'] }}</dd></div>
        </dl>
        @if($firstError)
            <p class="notice error">Corrija as linhas indicadas e envie a planilha novamente. <a href="#csv-row-{{ $firstError['line'] }}">Ver primeiro erro</a></p>
        @elseif($counts['new'] === 0)
            <p class="notice">Os produtos da planilha já estão cadastrados nesta loja.</p>
        @else
            <p>Os novos produtos começam com saldo zero. O controle de estoque segue a configuração da loja. Os SKUs já cadastrados mantêm seus dados atuais.</p>
        @endif
        <div class="actions">
            @if($preview['token'])
                <form method="post" action="{{ route('product.import.confirm', ['company' => $tenant->slug]) }}">
                    @csrf
                    <input type="hidden" name="token" value="{{ $preview['token'] }}">
                    <button type="submit" class="button">Cadastrar {{ $counts['new'] }} {{ $counts['new'] === 1 ? 'produto' : 'produtos' }}</button>
                </form>
            @endif
            <a class="button secondary" href="{{ route('product.import', ['company' => $tenant->slug]) }}">Escolher outro arquivo</a>
        </div>
    </section>
    <section class="panel product-import-rows" aria-labelledby="csv-rows-heading">
        <h2 id="csv-rows-heading">Linhas da planilha</h2>
        <div class="scroll">
            <table>
                <thead><tr><th scope="col">Linha</th><th scope="col">Produto / SKU</th><th scope="col">Grupo / unidade</th><th scope="col" class="num">Preço de venda</th><th scope="col">Situação</th></tr></thead>
                <tbody>
                @foreach($preview['rows'] as $row)
                    <tr id="csv-row-{{ $row['line'] }}" @if($row['status'] === 'error') tabindex="-1" @endif>
                        <td data-label="Linha">{{ $row['line'] }}</td>
                        <td class="product-name" data-label="Produto / SKU">
                            <strong>{{ $row['name'] }}</strong>
                            <small>{{ $row['sku'] }}</small>
                            @if($row['barcode'] !== '')<small>Código de barras {{ $row['barcode'] }}</small>@endif
                        </td>
                        <td data-label="Grupo / unidade">{{ $row['group'] }} · {{ $row['unit'] }}</td>
                        <td class="num" data-label="Preço de venda">{{ is_numeric($row['price']) ? 'R$ '.number_format((float)$row['price'], 2, ',', '.') : $row['price'] }}</td>
                        <td data-label="Situação">
                            <span class="badge {{ $row['status'] === 'error' ? 'bad' : '' }}">{{ $labels[$row['status']] }}</span>
                            @if($row['message'])<small>{{ $row['message'] }}</small>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
@endif
@endsection
