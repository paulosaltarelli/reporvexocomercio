@extends('layout')
@section('title','Regra fiscal · '.$product->name)
@section('subtitle','Preencha com os parâmetros específicos deste produto, validados pelo contador.')
@section('content')
<form class="panel" method="post">@csrf
<p>NCM, origem e unidade vêm do <a href="{{ route('product.edit',['company'=>$tenant->slug,'id'=>$product->id]) }}">cadastro do produto</a>. Este fluxo cobre ICMS simples sem ST ou CST 00/40/41/50, PIS/COFINS básicos e IBS/CBS integral. Operações especiais exigem extensão do cálculo; não adapte códigos para contornar uma limitação.</p>
@php($p=$rule?->parameters??[])
<div class="form-grid">
@foreach(['cfop'=>'CFOP interno','icms_code'=>'CST/CSOSN ICMS','icms_rate'=>'Alíquota ICMS (%) quando tributado','pis_code'=>'CST PIS','pis_rate'=>'Alíquota PIS (%) quando tributado','cofins_code'=>'CST COFINS','cofins_rate'=>'Alíquota COFINS (%) quando tributado','gtin'=>'GTIN ou SEM GTIN','cest'=>'CEST quando aplicável','transparency_rate'=>'Percentual total aproximado de tributos (%)','rtc_cst'=>'CST IBS/CBS (integral: 000)','rtc_class'=>'Classificação tributária IBS/CBS','ibs_uf_rate'=>'Alíquota IBS estadual (%)','ibs_municipal_rate'=>'Alíquota IBS municipal (%)','cbs_rate'=>'Alíquota CBS (%)','rtc_reason'=>'Fundamento de não incidência de IBS/CBS nesta operação'] as $field=>$label)
<div class="field"><label for="tax_{{ $field }}">{{ $label }}</label><input id="tax_{{ $field }}" name="{{ $field }}" value="{{ old($field,$p[$field]??'') }}" maxlength="255"></div>@endforeach
<div class="field"><label for="rtc_mode">Tratamento IBS/CBS validado</label><select id="rtc_mode" name="rtc_mode" required><option value="">Selecione</option><option value="standard" @selected(old('rtc_mode',$p['rtc_mode']??'')==='standard')>Tributação integral</option><option value="not_applicable" @selected(old('rtc_mode',$p['rtc_mode']??'')==='not_applicable')>Não aplicável, com fundamento informado</option></select></div>
<div class="field"><label for="accountant_reference">Responsável e referência da validação contábil</label><input id="accountant_reference" name="accountant_reference" value="{{ old('accountant_reference',$rule?->accountant_reference) }}" required maxlength="180"></div>
</div><p class="hint">Não há perfil tributário padrão. A base usa o valor vendido, nunca custo médio. Regras especiais de redução, substituição, monofásicos e benefícios não são cobertas por este cálculo inicial.</p><button class="primary">Salvar regra validada</button></form>
@endsection
