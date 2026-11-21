<!doctype html>
@php($defaultRoll=app(\App\Services\DeviceSettings::class)->current()['receipt_width'])
<html lang="pt-BR" data-roll="{{ $defaultRoll }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Impressão térmica</title>
    <link rel="stylesheet" href="/thermal-receipt.css?v={{ filemtime(public_path('thermal-receipt.css')) }}">
</head>
<body>
    <section class="print-tools" aria-label="Opções de impressão">
        <div class="print-actions">
            <a class="return-link" href="{{ $receiptBackUrl ?? request()->fullUrlWithoutQuery(['termica']) }}"><span aria-hidden="true">←</span> Voltar</a>
            <div class="roll-control"><label for="paper-width">Largura da bobina</label>
            <select id="paper-width">
                <option value="80" @selected($defaultRoll===80)>80 mm</option>
                <option value="58" @selected($defaultRoll===58)>58 mm</option>
            </select></div>
            <button type="button" id="print-ticket">Imprimir</button>
        </div>
        <p>Na janela do navegador, selecione a impressora, escolha a largura correspondente, use escala de 100% e desative cabeçalhos e rodapés.</p>
    </section>
    <main class="ticket {{ ($receiptSale ?? request()->routeIs('sale.show')) ? 'sale-ticket' : '' }}">
        @yield('content')
    </main>
    <script>
        const paper = document.getElementById('paper-width');
        const preference = @json('vendra.print.roll.'.$tenant->slug);
        try {
            const savedRoll = localStorage.getItem(preference);
            if (['58','80'].includes(savedRoll)) paper.value = savedRoll;
        } catch {}
        document.documentElement.dataset.roll = paper.value;
        paper.addEventListener('change', () => {
            document.documentElement.dataset.roll = paper.value;
            try { localStorage.setItem(preference, paper.value); } catch {}
        });
        document.getElementById('print-ticket').addEventListener('click', () => window.print());
    </script>
</body>
</html>
