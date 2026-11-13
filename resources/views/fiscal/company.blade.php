@include('fiscal.preferences',['settings'=>$fiscalSettings])
<section class="panel" id="fiscal-company"><h2>NFC-e · modelo 65 · configuração da empresa</h2>
<p>Comunicação direta com a <strong>SEFAZ em homologação, sem valor fiscal</strong>. O emitente e o endereço vêm do cadastro da empresa. A configuração, o certificado e a numeração pertencem exclusivamente a esta empresa. A emissão em produção permanece bloqueada.</p>
@if($fiscalCertificate)
<div class="notice"><strong>Certificado A1 cadastrado</strong><p>Validade: {{ \Carbon\Carbon::parse($fiscalCertificate['valid_from'])->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($fiscalCertificate['valid_until'])->format('d/m/Y') }}.</p>
@if(!empty($fiscalCertificate['certificate_fingerprint']))<p class="hint" style="overflow-wrap:anywhere">Impressão digital: {{ $fiscalCertificate['certificate_fingerprint'] }}</p>@endif
<p class="hint">Salvar não transmite uma nota. A validação abaixo confere a configuração cadastrada; não substitui o credenciamento na SEFAZ.</p></div>
@endif
<h3>1. Preparar dados e regras</h3><p>Salve aos poucos, mesmo sem certificado. Este rascunho não ativa a emissão nem reserva numeração.</p><form method="post" action="{{ route('business.fiscal',['company'=>$tenant->slug]) }}">@csrf
<input type="hidden" name="environment" value="homologation"><input type="hidden" name="intent" value="draft">
@php($fc=($fiscalDraftPresent??false)?($fiscalDraft??[]):($fiscalIssuer?->validated_configuration??[]))
@if(!($fiscalDraftPresent??false) && $fiscalNextNumber!==null)@php($fc['next_number']=$fiscalNextNumber)@endif
<div class="form-grid">
@foreach(['series'=>'Série NFC-e','next_number'=>'Próximo número disponível','natureza_operacao'=>'Natureza da operação (até 60 caracteres)','identification_limit'=>'Limite de identificação do consumidor (R$)','cancellation_minutes'=>'Prazo de cancelamento (minutos, até 30)','uf_validation_reference'=>'Referência da regra da UF confirmada pelo responsável'] as $field=>$label)
<div class="field"><label for="fc_{{ $field }}">{{ $label }}</label><input id="fc_{{ $field }}" name="{{ $field }}" @if(in_array($field,['series','next_number','identification_limit','cancellation_minutes'],true)) type="number" min="{{ $field==='next_number'?1:0 }}" max="{{ ['series'=>'889','next_number'=>'999999999','identification_limit'=>'999999999.99','cancellation_minutes'=>'30'][$field] }}" step="{{ $field==='identification_limit'?'0.01':'1' }}" @endif value="{{ old($field,$field==='next_number'?($fc[$field]??$fiscalNextNumber):($fc[$field]??'')) }}" maxlength="{{ $field==='natureza_operacao'?60:180 }}"></div>
@endforeach
<div class="field"><label for="fc_timezone">Fuso horário do emitente</label><select id="fc_timezone" name="timezone"><option value="">Selecione conforme o município</option>@foreach(['America/Sao_Paulo','America/Manaus','America/Porto_Velho','America/Rio_Branco','America/Boa_Vista','America/Cuiaba','America/Campo_Grande','America/Noronha','America/Bahia','America/Fortaleza','America/Recife','America/Belem','America/Santarem','America/Maceio','America/Araguaina'] as $tz)<option value="{{ $tz }}" @selected(old('timezone',$fc['timezone']??'')===$tz)>{{ $tz }}</option>@endforeach</select></div>
<div class="field"><label for="fc_crt">Regime tributário (CRT) confirmado</label><select id="fc_crt" name="crt"><option value="">Selecione</option>@foreach(['1'=>'1 · Simples Nacional','2'=>'2 · Simples, excesso de sublimite','3'=>'3 · Regime normal','4'=>'4 · Simples Nacional MEI'] as $value=>$label)<option value="{{ $value }}" @selected((string)old('crt',$fc['crt']??'')===(string)$value)>{{ $label }}</option>@endforeach</select></div>
</div><p class="hint">Se o regime tributário ainda não foi confirmado, deixe o CRT em branco e salve o rascunho. Não escolha um regime apenas para avançar. Série e próximo número devem ser conferidos com o histórico de emissões da empresa.</p>
@if(($profile->state??'')==='MG')
<details class="disclosure"><summary>Referência para configuração em Minas Gerais</summary><div class="disclosure-content"><p>Sugestões para conferência: limite de identificação R$ 10.000,00; cancelamento até 30 minutos; fuso America/Sao_Paulo; natureza “VENDA DE MERCADORIA”. Consulte a regra vigente e sua aplicação nos arts. 29 e 40 da regulamentação NFC-e/MG antes de informar os campos. Estes valores não são aplicados automaticamente.</p></div></details>
@endif
<div class="actions"><button class="primary">Salvar rascunho sem certificado</button></div></form>
<h3 style="margin-top:32px">2. Ativar configuração para homologação SEFAZ</h3><p>Após salvar e conferir os dados da etapa 1, informe o certificado e o CSC. A ativação confere os dados completos e não emite uma nota.</p>
<form method="post" enctype="multipart/form-data" action="{{ route('business.fiscal',['company'=>$tenant->slug]) }}">
@csrf
<input type="hidden" name="intent" value="activate"><input type="hidden" name="environment" value="homologation">
@foreach($fc as $field=>$value)
@if(in_array($field,['series','next_number','natureza_operacao','identification_limit','cancellation_minutes','uf_validation_reference','timezone','crt'],true))
<input type="hidden" name="{{ $field }}" value="{{ $value }}">
@endif
@endforeach
@if(!array_key_exists('next_number',$fc))<input type="hidden" name="next_number" value="{{ $fiscalNextNumber }}">@endif
<div class="form-grid">
<input type="hidden" name="csc_required" value="1">
<div class="field"><label for="fc_certificate">Certificado A1 (.pfx ou .p12, até 2 MB)</label><input id="fc_certificate" type="file" name="certificate" accept=".pfx,.p12" @required(!$fiscalCertificate)></div>
<div class="field"><label for="fc_certificate_password">Senha do certificado A1 enviado</label><input id="fc_certificate_password" type="password" name="certificate_password" maxlength="256" autocomplete="new-password"></div>
<div class="field"><label for="fc_csc">CSC do contribuinte</label><input id="fc_csc" type="password" name="csc" maxlength="100" autocomplete="new-password"></div>
<div class="field"><label for="fc_csc_id">Identificador do CSC do contribuinte</label><input id="fc_csc_id" name="csc_id" autocomplete="off" inputmode="numeric" pattern="[0-9]{1,6}" maxlength="6"></div>
</div><p class="hint">A senha e o CSC não são exibidos novamente. Deixe os campos de credenciais vazios para manter os dados cadastrados. Para substituir o certificado, envie o arquivo e sua senha. Confira série e próximo número com o responsável fiscal para evitar conflito com emissões anteriores. Os limites de identificação e cancelamento devem seguir a regra vigente da UF. Em MG, a SEF informa que CSC e identificador são os mesmos para homologação e produção; este sistema permanece em homologação.</p><button class="primary">Ativar configuração de homologação</button></form>
@if($fiscalIssuer)
<form method="post" action="{{ route('business.fiscal.validate',['company'=>$tenant->slug]) }}" style="margin-top:24px">
@csrf
<p class="hint">Valide os dados salvos e o certificado antes do primeiro teste. Esta ação não emite nota nem envia o certificado a um intermediário.</p>
<button class="button secondary">Validar configuração de homologação</button>
</form>
@endif
</section>

@include('fiscal.nfe.company',['nfeConfiguration'=>$nfeConfiguration??null,'nfeNextNumber'=>$nfeNextNumber??null])
