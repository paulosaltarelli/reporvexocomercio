<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas de gôndola · {{ $tenant->name }}</title>
    <link rel="stylesheet" href="/product-labels.css?v={{ filemtime(public_path('product-labels.css')) }}">
    @if($format==='roll')<style>@page {size: 80mm 50mm; margin: 0}</style>@endif
</head>
<body class="{{ $format==='roll'?'roll-labels':'sheet-labels' }}">
    <header class="label-tools">
        <div><h1>Etiquetas de gôndola</h1><p>{{ array_sum($quantities) }} {{ array_sum($quantities)===1?'etiqueta':'etiquetas' }} · {{ $format==='roll'?'Bobina de 80 × 50 mm':'Folha A4 para recorte' }}</p></div>
        <nav><a href="{{ route('product.labels',['company'=>$tenant->slug,'qty'=>$quantities,'format'=>$format]) }}">Alterar seleção</a> <button type="button" id="print-labels">Imprimir</button></nav>
        <small>Na impressão, use escala de 100% e desative os cabeçalhos e rodapés do navegador.</small>
    </header>
    <main class="labels">
        @foreach($labels as $label)
        @php($product = $label['product'])
        @php($barcode = $label['barcode'])
        @for($i=0;$i<$label['copies'];$i++)
        <article class="shelf-label">
            <div class="store">{{ $companyProfile?->trade_name ?: $tenant->name }}</div>
            <h2>{{ $product->name }}</h2>
            <p class="price"><strong>R$ {{ number_format($product->sale_price,2,',','.') }}</strong> / {{ $product->unit }}</p>
            @if($barcode)
                <svg class="barcode" aria-hidden="true" shape-rendering="crispEdges" width="{{ min(70,$barcode['modules']*.33) }}mm" height="17mm" viewBox="-12 0 {{ $barcode['modules'] }} 1" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                    @foreach($barcode['bars'] as [$x,$y,$w,$h])<rect x="{{ $x }}" y="{{ $y }}" width="{{ $w }}" height="{{ $h }}"/>@endforeach
                </svg>
                <small class="identifier">{{ $barcode['field']==='sku'?'SKU ':'' }}{{ $barcode['code'] }}</small>
            @else
                <small class="manual-code">Consulta pelo nome · SKU {{ $product->sku }}</small>
            @endif
        </article>
        @endfor
        @endforeach
    </main>
    <script>document.getElementById('print-labels').addEventListener('click', () => window.print());</script>
</body>
</html>
