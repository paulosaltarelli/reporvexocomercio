@include('fiscal.preferences',['settings'=>$fiscalSettings])
<section class="panel" id="fiscal-company"><h2>Emissão fiscal da empresa</h2>
<p>Comunicação direta com a <strong>SEFAZ em homologação, sem valor fiscal</strong>. O emitente e o endereço vêm do cadastro da empresa. A configuração, o certificado e a numeração pertencem exclusivamente a esta empresa. A emissão em produção permanece bloqueada.</p>
@if($fiscalCertificate)
<div class="notice"><strong>Certificado A1 cadastrado</strong><p>Validade: {{ \Carbon\Carbon::parse($fiscalCertificate['valid_from'])->format('d/m/Y') }} a {{ \Carbon\Carbon::parse($fiscalCertificate['valid_until'])->format('d/m/Y') }}.</p>
@if(!empty($fiscalCertificate['certificate_fingerprint']))<p class="hint" style="overflow-wrap:anywhere">Impressão digital: {{ $fiscalCertificate['certificate_fingerprint'] }}</p>@endif
<p class="hint">Salvar não transmite uma nota. A validação abaixo confere a configuração cadastrada; não substitui o credenciamento na SEFAZ.</p></div>
@endif
<form method="post" enctype="multipart/form-data" action="{{ route('business.fiscal',['company'=>$tenant->slug]) }}">@csrf
<input type="hidden" name="environment" value="homologation">
@php($fc=$fiscalIssuer?->validated_configuration??[])
<div class="form-grid">
@foreach(['series'=>'Série NFC-e','next_number'=>'Próximo número disponível','natureza_operacao'=>'Natureza da operação (até 60 caracteres)','identification_limit'=>'Limite de identificação do consumidor (R$)','cancellation_minutes'=>'Prazo de cancelamento (minutos, até 30)','uf_validation_reference'=>'Referência da regra da UF validada pelo contador'] as $field=>$label)
<div class="field"><label for="fc_{{ $field }}">{{ $label }}</label><input id="fc_{{ $field }}" name="{{ $field }}" @if(in_array($field,['series','next_number','identification_limit','cancellation_minutes'],true)) type="number" min="{{ $field==='next_number'?1:0 }}" max="{{ ['series'=>'889','next_number'=>'999999999','identification_limit'=>'999999999.99','cancellation_minutes'=>'30'][$field] }}" step="{{ $field==='identification_limit'?'0.01':'1' }}" @endif value="{{ old($field,$field==='next_number'?$fiscalNextNumber:($fc[$field]??'')) }}" required maxlength="{{ $field==='natureza_operacao'?60:180 }}"></div>
@endforeach
<div class="field"><label for="fc_timezone">Fuso horário do emitente</label><select id="fc_timezone" name="timezone" required><option value="">Selecione conforme o município</option>@foreach(['America/Sao_Paulo','America/Manaus','America/Porto_Velho','America/Rio_Branco','America/Boa_Vista','America/Cuiaba','America/Campo_Grande','America/Noronha','America/Bahia','America/Fortaleza','America/Recife','America/Belem','America/Santarem','America/Maceio','America/Araguaina'] as $tz)<option value="{{ $tz }}" @selected(old('timezone',$fc['timezone']??'')===$tz)>{{ $tz }}</option>@endforeach</select></div>
<div class="field"><label for="fc_crt">CRT confirmado pelo contador</label><select id="fc_crt" name="crt" required><option value="">Selecione</option>@foreach(['1'=>'1 · Simples Nacional','2'=>'2 · Simples, excesso de sublimite','3'=>'3 · Regime normal','4'=>'4 · Simples Nacional MEI'] as $value=>$label)<option value="{{ $value }}" @selected((string)old('crt',$fc['crt']??'')===(string)$value)>{{ $label }}</option>@endforeach</select></div>
<input type="hidden" name="csc_required" value="1">
<div class="field"><label for="fc_certificate">Certificado A1 (.pfx ou .p12, até 2 MB)</label><input id="fc_certificate" type="file" name="certificate" accept=".pfx,.p12" @required(!$fiscalCertificate)></div>
<div class="field"><label for="fc_certificate_password">Senha do certificado A1 enviado</label><input id="fc_certificate_password" type="password" name="certificate_password" maxlength="256" autocomplete="new-password"></div>
<div class="field"><label for="fc_csc">CSC de homologação</label><input id="fc_csc" type="password" name="csc" maxlength="100" autocomplete="new-password"></div>
<div class="field"><label for="fc_csc_id">Identificador do CSC de homologação</label><input id="fc_csc_id" name="csc_id" autocomplete="off" inputmode="numeric" pattern="[0-9]{1,6}" maxlength="6"></div>
</div><p class="hint">A senha e o CSC não são exibidos novamente. Deixe os campos de credenciais vazios para manter os dados cadastrados. Para substituir o certificado, envie o arquivo e sua senha. Confira série e próximo número com o responsável fiscal para evitar conflito com emissões anteriores. Os limites de identificação e cancelamento devem seguir a regra vigente da UF; não são definidos automaticamente.</p><button class="primary">Salvar configuração e certificado</button></form>
@if($fiscalIssuer)
<form method="post" action="{{ route('business.fiscal.validate',['company'=>$tenant->slug]) }}" style="margin-top:24px">
@csrf
<p class="hint">Valide os dados salvos e o certificado antes do primeiro teste. Esta ação não emite nota nem envia o certificado a um intermediário.</p>
<button class="button secondary">Validar configuração de homologação</button>
</form>
@endif
</section>
