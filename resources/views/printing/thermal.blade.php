<!doctype html>
<html lang="pt-BR" data-roll="80">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Impressão térmica</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: #f4f7fb; color: #111; font: 11px/1.35 Arial, sans-serif; }
        .print-tools { margin: 0 auto 16px; max-width: 600px; padding: 16px; border-radius: 10px; background: white; font: 14px/1.5 system-ui, sans-serif; }
        .print-actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; }
        .print-tools button, .print-tools select { min-height: 40px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 6px 10px; font: inherit; }
        .print-tools button { background: #145ccc; color: white; cursor: pointer; }
        .print-tools p { margin: 8px 0 0; }
        .ticket { width: 72mm; max-width: 100%; margin: auto; padding: 3mm 1mm; background: white; overflow-wrap: anywhere; }
        [data-roll="58"] .ticket { width: 52mm; }
        header { text-align: center; }
        h1 { margin: 0 0 4px; font-size: 16px; }
        h2 { margin: 0 0 4px; font-size: 12px; }
        p { margin: 2px 0; }
        .block { margin-top: 8px; padding-top: 6px; border-top: 1px dashed; }
        .row { display: flex; justify-content: space-between; gap: 6px; }
        .amount { white-space: nowrap; font-variant-numeric: tabular-nums; }
        .entry, .total, header { break-inside: avoid; }
        .entry + .entry { margin-top: 5px; }
        .total { font-size: 13px; font-weight: bold; }
        @page { margin: 0; }
        @media print {
            body { background: white; padding: 0; }
            .print-tools { display: none; }
        }
    </style>
</head>
<body>
    <section class="print-tools" aria-label="Opções de impressão">
        <div class="print-actions">
            <a href="{{ request()->fullUrlWithoutQuery(['termica']) }}">Voltar</a>
            <label for="paper-width">Largura da bobina</label>
            <select id="paper-width">
                <option value="80">80 mm</option>
                <option value="58">58 mm</option>
            </select>
            <button type="button" id="print-ticket">Imprimir</button>
        </div>
        <p>Na janela do navegador, selecione a impressora, escolha a largura correspondente, use escala de 100% e desative cabeçalhos e rodapés.</p>
    </section>
    <main class="ticket">
        @yield('content')
    </main>
    <script>
        const paper = document.getElementById('paper-width');
        const preference = @json('vendra.print.roll.'.$tenant->slug);
        try {
            if (localStorage.getItem(preference) === '58') paper.value = '58';
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
