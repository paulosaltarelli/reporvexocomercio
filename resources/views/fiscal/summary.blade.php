@php($snapshot=$report['snapshot'])
<h2>{{ $snapshot['issuer']['legal_name']??'Emitente ainda não cadastrado' }}</h2>
<p>{{ $snapshot['issuer']['city']??'Município não informado' }} / {{ $snapshot['issuer']['state']??'UF não informada' }} · Homologação</p>
<p>Destinatário: {{ $snapshot['recipient']['name']??'Consumidor não identificado' }}</p>
<div class="scroll"><table><thead><tr><th>Item vendido</th><th>Tipo</th><th class="num">Quantidade</th><th class="num">Total</th></tr></thead><tbody>
@foreach($snapshot['items'] as $item)<tr><td>{{ $item['description'] }}</td><td>{{ ['goods'=>'Mercadoria','services'=>'Serviço · fora da NFC-e','unclassified'=>'Revisar vínculo'][$item['kind']] }}</td><td class="num">{{ number_format((float)$item['quantity'],3,',','.') }}</td><td class="num">R$ {{ number_format((float)$item['total'],2,',','.') }}</td></tr>@endforeach
</tbody></table></div>
<p><strong>Mercadorias:</strong> R$ {{ number_format((float)$snapshot['totals']['goods'],2,',','.') }} · <strong>Serviços:</strong> R$ {{ number_format((float)$snapshot['totals']['services'],2,',','.') }}
@if(bccomp($snapshot['totals']['unclassified'],'0',2)!==0) · <strong>Sem classificação:</strong> R$ {{ number_format((float)$snapshot['totals']['unclassified'],2,',','.') }}@endif</p>
<p class="hint">Valores preservados da venda. A separação usa a forma de pagamento comercial declarada e não representa recebimento nem cálculo fiscal. Dados tributários do cadastro são capturados na conferência e ainda precisam ser validados.</p>
