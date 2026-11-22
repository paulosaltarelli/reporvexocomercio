@extends('layout')
@section('title', 'Reposição de estoque')
@section('navigation-section', 'Estoque e compras')
@section('subtitle', 'Consulte quanto falta para atingir o estoque mínimo de cada produto.')
@section('content')
@php
    $formatQuantity = static function (string $value): string {
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        return preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer).($fraction === '' ? '' : ','.$fraction);
    };
@endphp
<section class="panel">
    <form class="toolbar" method="get" aria-label="Filtros de reposição">
        <input type="search" name="q" value="{{ $term }}" maxlength="100" placeholder="Buscar por nome ou SKU" aria-label="Nome ou SKU do produto">
        <select name="sales" aria-label="Filtrar por histórico de vendas">
            <option value="recent" @selected($recentOnly)>Vendidos nos últimos 60 dias</option>
            <option value="all" @selected(!$recentOnly)>Todos abaixo do mínimo</option>
        </select>
        <button type="submit" class="button secondary">Filtrar</button>
    </form>
    <p class="hint">{{ $products->total() }} {{ $products->total() === 1 ? 'produto abaixo do mínimo' : 'produtos abaixo do mínimo' }}@if($recentOnly) com venda concluída de {{ $since->format('d/m/Y') }} a {{ $now->format('d/m/Y') }}@endif.</p>
    <div class="scroll">
        <table>
            <thead><tr><th>Produto / SKU</th><th class="num">Saldo atual</th><th class="num">Estoque mínimo</th><th class="num">Quantidade a repor</th><th>Outras unidades de compra</th></tr></thead>
            <tbody>
            @forelse($products as $row)
                @php($product = $row['product'])
                <tr>
                    <td class="product-name"><strong>{{ $product->name }}</strong><small class="hint">{{ $product->sku }}</small></td>
                    <td class="num">{{ $formatQuantity($product->quantity) }} {{ $product->unit }}</td>
                    <td class="num">{{ $formatQuantity($product->minimum_stock) }} {{ $product->unit }}</td>
                    <td class="num"><strong>{{ $formatQuantity($row['required']) }} {{ $product->unit }}</strong></td>
                    <td>
                        @forelse($row['equivalents'] as $unit => $equivalent)
                            <div>
                                @if($equivalent['quantity'] !== null)<strong>{{ $formatQuantity($equivalent['quantity']) }} {{ $unit }}</strong> · @endif
                                1 {{ $unit }} = {{ $formatQuantity($equivalent['factor']) }} {{ $product->unit }}
                            </div>
                        @empty
                            <span aria-label="Somente unidade de estoque">—</span>
                        @endforelse
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">
                    Nenhum produto abaixo do mínimo para estes filtros.
                    @if($recentOnly)<a href="{{ route('stock.replenishment', ['company' => $tenant->slug, 'sales' => 'all', 'q' => $term]) }}">Incluir produtos sem vendas recentes</a>@endif
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    {{ $products->links() }}
</section>
@endsection
