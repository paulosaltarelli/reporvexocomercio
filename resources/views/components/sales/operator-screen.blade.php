<button type="button" class="button secondary no-print" data-pos-fullscreen aria-pressed="false">
    <span>Tela cheia</span> <kbd>F10</kbd>
</button>
<span class="hint no-print" data-pos-fullscreen-status role="status"></span>
@push('scripts')
    <link rel="stylesheet" href="/pos-operator-screen.css?v={{ filemtime(public_path('pos-operator-screen.css')) }}">
    <script src="/pos-operator-screen.js?v={{ filemtime(public_path('pos-operator-screen.js')) }}" defer></script>
@endpush
