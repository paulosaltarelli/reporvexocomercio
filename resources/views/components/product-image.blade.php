@php
    $productImages = $productImages ?? app(\App\Services\ProductImages::class);
    $productArtwork = $imageArtwork ?? $productImages->presentation($tenant->slug, $tenant->id, $imageProductId ?? $product->id);
@endphp
@once
    @push('styles')<link rel="stylesheet" href="/product-images.css?v={{ filemtime(public_path('product-images.css')) }}">@endpush
@endonce
@if($productArtwork)
    @if($productArtwork['sheet'])
        <div class="product-artwork product-artwork-sheet" role="img" aria-label="{{ $imageDescription ?? $product->name }}" style="background-image:url('{{ $productArtwork['url'] }}');background-size:{{ $productArtwork['size_x'] }}% {{ $productArtwork['size_y'] }}%;background-position:{{ $productArtwork['position_x'] }}% {{ $productArtwork['position_y'] }}%;aspect-ratio:{{ $productArtwork['aspect_width'] }}/{{ $productArtwork['aspect_height'] }}"></div>
    @else
        <img class="product-artwork" src="{{ $productArtwork['url'] }}" alt="{{ $imageDescription ?? $product->name }}" loading="lazy" width="240" height="180">
    @endif
@endif
