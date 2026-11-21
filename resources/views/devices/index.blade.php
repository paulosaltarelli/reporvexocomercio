@extends('layout')
@section('title','Dispositivos e impressão')
@section('subtitle','Configure a impressão pelo navegador e confira os dispositivos usados no atendimento.')
@section('content')
<form method="post" action="{{ route('devices.save',['company'=>$tenant->slug]) }}" class="panel">
    @csrf<input type="hidden" name="version" value="{{ old('version',$deviceVersion) }}">
    <h2>Preferências da empresa</h2>
    <div class="form-grid">
        <div class="field"><label for="device-width">Largura padrão da bobina</label><select name="receipt_width" id="device-width">@foreach([80,58] as $width)<option value="{{ $width }}" @selected((int)old('receipt_width',$deviceSettings['receipt_width'])===$width)>{{ $width }} mm</option>@endforeach</select><small class="hint">Cada computador também pode escolher sua largura ao imprimir.</small></div>
        <div class="field"><label for="device-printer">Identificação da impressora (opcional)</label><input id="device-printer" name="printer_label" maxlength="100" value="{{ old('printer_label',$deviceSettings['printer_label']) }}" placeholder="Ex.: Epson TM-T20X-II do caixa"><small class="hint">A impressora é escolhida na janela de impressão do navegador.</small></div>
        <div class="field"><label for="device-reader">Entrada de produtos</label><select id="device-reader" name="reader"><option value="keyboard" @selected(old('reader',$deviceSettings['reader'])==='keyboard')>Leitor de código de barras como teclado</option><option value="manual" @selected(old('reader',$deviceSettings['reader'])==='manual')>Digitação manual</option></select></div>
        <div class="field"><label for="device-weight">Pesagem</label><select id="device-weight" name="weight_input"><option value="manual">Peso digitado ou etiqueta de balança</option></select><small class="hint">A leitura de etiquetas usa os parâmetros definidos em Operação e implantação.</small></div>
    </div>
    <div class="actions"><button class="primary">Salvar preferências</button><a class="button secondary" target="_blank" rel="noopener" href="{{ route('devices.print-test',['company'=>$tenant->slug]) }}">Abrir teste de impressão</a></div>
</form>
<section class="panel"><h2>Conferir o equipamento</h2>
    <ol><li>Instale a impressora no computador e confirme uma impressão de teste pelo sistema operacional.</li><li>Abra o teste acima, selecione a impressora, a largura da bobina e escala de 100% na janela do navegador.</li><li>Desative cabeçalhos e rodapés. Confira o alinhamento dos valores e a legibilidade do texto.</li><li>No caixa, leia um produto cadastrado com o leitor e confira o código, a unidade e a quantidade antes de receber.</li></ol>
    <p class="hint">A Epson TM-T20X-II USB pode ser usada pela impressão do navegador com o driver instalado. Cozinha e etiquetas de preço usam a impressora e o papel selecionados em cada computador.</p>
</section>
<section class="panel"><h2>Integrações que dependem do equipamento</h2>
    <p>Conexão direta com balança e pagamento integrado por TEF precisam dos modelos, da operadora e de homologação. Nesta versão, o peso pode ser digitado ou lido na etiqueta, e o pagamento feito na maquininha pode ser registrado no caixa.</p>
    @if(app(\App\Services\OperationalModes::class)->enabled('supermarket') && $currentUser->allows('supermarket.supervise'))<p><a href="{{ route('supermarket.hardware.index',['company'=>$tenant->slug]) }}">Consultar diagnóstico dos dispositivos do supermercado</a></p>@endif
</section>
@endsection
