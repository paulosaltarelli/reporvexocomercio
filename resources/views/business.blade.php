@extends('layout')
@section('title','Minha empresa')
@section('subtitle','Identificação e dados preparatórios para a futura integração fiscal.')
@section('content')
<form class="panel" method="post" enctype="multipart/form-data">@csrf<h2>Identificação</h2><div class="form-grid">
@foreach(['legal_name'=>'Razão social','trade_name'=>'Nome fantasia','document'=>'CNPJ','email'=>'E-mail','phone'=>'Telefone'] as $field=>$label)
<div class="field"><label>{{ $label }}</label><input name="{{ $field }}" value="{{ old($field,$profile->$field) }}" type="{{ $field==='email'?'email':'text' }}" @required($field==='legal_name') maxlength="{{ ['document'=>18,'phone'=>30][$field]??180 }}"></div>
@endforeach</div>
<section class="company-logo-editor" aria-labelledby="company-logo-title">
    <h2 id="company-logo-title">Logotipo da empresa</h2>
    <div class="company-logo-edit-grid">
        <div class="company-logo-preview"><img id="company-logo-preview" src="{{ route('business.logo',['company'=>$tenant->slug,'v'=>$profile->updated_at?->timestamp??0]) }}" alt="{{ $profile->logo_path?'Logotipo atual da empresa':'Marca Vendra usada como padrão' }}" width="200" height="80"></div>
        <div>
            <div class="field"><label for="company-logo-upload">Escolher logotipo</label><input id="company-logo-upload" name="logo" type="file" accept="image/png,image/jpeg,image/webp"><small class="hint">PNG, JPEG ou WebP, até 2 MB. Dimensões entre 48 × 48 e 4096 × 4096 pixels. A imagem é ajustada pelo sistema.</small></div>
            @if($profile->logo_path)
                <input type="hidden" name="remove_logo" value="0"><label class="checkbox-line" style="margin-top:14px"><input id="remove-company-logo" type="checkbox" name="remove_logo" value="1" @checked(old('remove_logo'))> Remover o logotipo atual e usar a marca Vendra</label>
            @endif
            <p class="hint" id="company-logo-status" role="status">{{ $profile->logo_path?'O logotipo atual será mantido se você não escolher outro arquivo.':'Sem logotipo próprio, a marca Vendra é usada como padrão.' }}</p>
            <p class="hint">A alteração será aplicada ao salvar os dados da empresa.</p>
        </div>
    </div>
</section>
<details class="disclosure" style="margin-top:24px"><summary>Endereço e dados fiscais preparatórios</summary><div class="disclosure-content form-grid">
@foreach(['street'=>'Logradouro','number'=>'Número','district'=>'Bairro','city'=>'Cidade','postal_code'=>'CEP','municipality_code'=>'Código IBGE do município','municipal_registration'=>'Inscrição municipal','state_registration'=>'Inscrição estadual'] as $field=>$label)
<div class="field"><label>{{ $label }}</label><input name="{{ $field }}" value="{{ old($field,$profile->$field) }}" maxlength="{{ ['street'=>180,'number'=>20,'district'=>100,'city'=>100,'postal_code'=>9,'municipality_code'=>7,'municipal_registration'=>30,'state_registration'=>30][$field] }}"></div>
@endforeach
<div class="field"><label>Estado</label><select name="state"><option value="">Não informado</option>
@foreach(explode(',','AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO') as $uf)<option @selected(old('state',$profile->state)===$uf)>{{ $uf }}</option>
@endforeach</select></div><div class="field"><label>Regime tributário</label><select name="tax_regime"><option value="">Não informado</option>
@foreach(['mei'=>'MEI','simples'=>'Simples Nacional','presumido'=>'Lucro Presumido','real'=>'Lucro Real'] as $value=>$label)<option value="{{ $value }}" @selected(old('tax_regime',$profile->tax_regime)===$value)>{{ $label }}</option>
@endforeach</select></div></div></details><p class="hint">Dados cadastrais sujeitos à conferência contábil. Não há cálculo de tributos, emissão de nota, integração municipal ou validação cadastral na Receita nesta etapa.</p><div class="actions"><button class="primary">Salvar dados da empresa</button></div></form>
@endsection

@push('scripts')
<script>
(() => {
    const upload=document.querySelector('#company-logo-upload');
    const preview=document.querySelector('#company-logo-preview');
    const remove=document.querySelector('#remove-company-logo');
    const status=document.querySelector('#company-logo-status');
    const currentSource=preview.src;
    const currentAlt=preview.alt;
    let objectUrl=null;
    function updateLogo(){
        if(objectUrl){URL.revokeObjectURL(objectUrl);objectUrl=null;}
        upload.setCustomValidity('');upload.disabled=Boolean(remove?.checked);
        if(remove?.checked){preview.src='/brand/vendra-logo.svg';preview.alt='Marca Vendra usada como padrão';status.textContent='O logotipo próprio será removido ao salvar.';return;}
        const file=upload.files?.[0];
        if(!file){preview.src=currentSource;preview.alt=currentAlt;status.textContent='A imagem atual será mantida. Escolha um arquivo para substituí-la.';return;}
        if(file.size>2*1024*1024){upload.setCustomValidity('O logotipo deve ter no máximo 2 MB.');status.textContent='O arquivo ultrapassa 2 MB. Escolha uma imagem menor.';preview.src=currentSource;preview.alt=currentAlt;return;}
        if(file.type&&!['image/png','image/jpeg','image/webp'].includes(file.type)){upload.setCustomValidity('Escolha uma imagem PNG, JPEG ou WebP.');status.textContent='Formato não aceito. Use PNG, JPEG ou WebP.';preview.src=currentSource;preview.alt=currentAlt;return;}
        objectUrl=URL.createObjectURL(file);preview.src=objectUrl;preview.alt='Prévia do logotipo selecionado';status.textContent='Prévia local. O sistema validará e salvará a imagem ao confirmar.';
    }
    upload.addEventListener('change',updateLogo);
    remove?.addEventListener('change',updateLogo);
    if(remove?.checked)updateLogo();
    window.addEventListener('pagehide',()=>{if(objectUrl)URL.revokeObjectURL(objectUrl);});
})();
</script>
@endpush
