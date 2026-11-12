<form class="panel" method="post" action="{{ route('fiscal.settings',['company'=>$tenant->slug]) }}">@csrf
<h2>Configuração de homologação</h2><div class="form-grid">
<div class="field"><label for="environment">Ambiente</label><select id="environment" name="environment"><option value="homologation">Homologação · sem valor fiscal</option></select></div>
<div class="field"><label for="provider">Comunicação fiscal</label><select id="provider" name="provider"><option value="sefaz_direct">Direta com a SEFAZ</option></select></div>
<div class="field"><label for="operation">Operação pretendida</label><select id="operation" name="operation"><option value="">Não informada</option>@foreach(['presential'=>'Presencial','delivery'=>'Delivery','both'=>'Presencial e delivery'] as $value=>$label)<option value="{{ $value }}" @selected(old('operation',$settings?->operation)===$value)>{{ $label }}</option>@endforeach</select></div>
<div class="field"><label for="nfce_accredited">Credenciamento NFC-e de homologação</label><select id="nfce_accredited" name="nfce_accredited"><option value="0">Não confirmado</option><option value="1" @selected((string)old('nfce_accredited',(int)($settings?->nfce_accredited??false))==='1')>Confirmado pelo responsável na SEFAZ</option></select></div>
<div class="field"><label for="accountant_name">Contador responsável</label><input id="accountant_name" name="accountant_name" maxlength="180" value="{{ old('accountant_name',$settings?->accountant_name) }}"></div>
<div class="field"><label for="accountant_email">E-mail do contador</label><input id="accountant_email" name="accountant_email" type="email" maxlength="180" value="{{ old('accountant_email',$settings?->accountant_email) }}"></div>
</div><p class="hint">Informar o contador e o credenciamento não aprova regras tributárias nem libera produção. Não cadastre certificado, senha ou CSC neste formulário.</p>
<button class="primary">Salvar configuração de homologação</button></form>
