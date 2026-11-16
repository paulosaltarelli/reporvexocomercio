@extends('layout')
@section('title','Operação e implantação')
@section('subtitle','Configure a empresa uma vez. Ajuste os módulos conforme a rotina, sem versões diferentes do sistema.')
@section('content')
@php($steps=[1=>'Empresa',2=>'Operação',3=>'Fiscal',4=>'Equipe',5=>'Revisão'])
<nav class="panel setup-steps" aria-label="Etapas de implantação">
@foreach($steps as $number=>$label)
<a class="button {{ $step===$number?'primary':'secondary' }}" href="{{ route('setup',['company'=>$tenant->slug,'step'=>$number]) }}" @if($step===$number) aria-current="step" @endif>{{ $number }}. {{ $label }}</a>
@endforeach
</nav>
@if($setup?->completed_at)
<p class="notice success">Implantação conferida em {{ $setup->completed_at->format('d/m/Y H:i') }}. Alterar a operação exige uma nova revisão.</p>
@else
<p class="form-note">Você pode salvar e continuar depois. As configurações salvas valem imediatamente para novas operações. O histórico é preservado.</p>
@endif
@if($step===1)
@include('components.company-profile-form',['companyFormAction'=>route('setup.company.save',['company'=>$tenant->slug]),'companySubmitLabel'=>'Salvar empresa e continuar','wizardCompany'=>true])
@elseif($step===2)
<form class="panel" method="post" action="{{ route('setup.save',['company'=>$tenant->slug]) }}">@csrf
<input type="hidden" name="version" value="{{ old('version',$setup?->version??0) }}">
<h2>Como a empresa trabalha</h2>
<div class="form-grid">
<div><label for="catalog-mode">O que comercializa?</label><select id="catalog-mode" name="catalog_mode">@foreach(['mixed'=>'Produtos e serviços','products'=>'Somente produtos','services'=>'Somente serviços'] as $value=>$label)<option value="{{ $value }}" @selected(old('catalog_mode',$configuration['catalog_mode'])===$value)>{{ $label }}</option>@endforeach</select></div>
<div><label for="commission-basis">Regime de comissão das novas vendas</label><select id="commission-basis" name="commission_basis"><option value="cash" @selected(old('commission_basis',$configuration['commission_basis'])==='cash')>Caixa · proporcional ao recebimento</option><option value="accrual" @selected(old('commission_basis',$configuration['commission_basis'])==='accrual')>Competência · na conclusão da venda</option></select></div>
</div>
<p class="form-note">Somente serviços: desabilite estoque, NFC-e e NF-e. Somente produtos: desabilite NFS-e e OS. Para restringir tipos, desative antes os itens incompatíveis e resolva documentos pendentes.</p>
@foreach(['Rotina comercial'=>['inventory'=>'Gestão de estoque','quotes'=>'Orçamentos','work_orders'=>'Ordens de serviço','commissions'=>'Comissões por vendedor','discounts'=>'Descontos dentro dos limites do grupo ou produto'],'Documentos fiscais'=>['nfce'=>'NFC-e · consumidor final (modelo 65)','nfe'=>'NF-e · mercadorias (modelo 55)','nfse'=>'NFS-e · serviços']] as $heading=>$fields)
<fieldset><legend>{{ $heading }}</legend><div class="setup-options">
@foreach($fields as $key=>$label)
<div><input type="hidden" name="{{ $key }}" value="0"><label class="setup-option" for="config-{{ $key }}"><input id="config-{{ $key }}" type="checkbox" name="{{ $key }}" value="1" @checked(old($key,$configuration[$key]))> {{ $label }}</label></div>
@endforeach
</div></fieldset>
@endforeach
<p class="form-note">Estoque: custo médio ponderado e política de preço automático/alerta continuam configuráveis por grupo ou produto. Descontos ainda respeitam os limites individuais. Desativar comissões zera a comissão de novas vendas; não apaga valores anteriores. O regime é registrado em cada venda. O relatório atual reconcilia recebimentos acumulados das vendas do período; não é um demonstrativo de pagamentos ao vendedor.</p>
<button class="primary">Salvar e continuar</button>
</form>
@elseif($step===3)
<section class="panel"><h2>Configuração fiscal</h2><p>Preencha e salve os dados nesta etapa. Eles ficam disponíveis nas configurações fiscais definitivas da empresa. A emissão em produção permanece bloqueada.</p>
@if(!$configuration['nfce']&&!$configuration['nfe']&&!$configuration['nfse'])<p>Nenhum módulo fiscal foi selecionado. Você pode concluir a implantação sem certificado e configurar o fiscal posteriormente.</p>@else<p>Use os dados confirmados pelo responsável fiscal. O certificado e sua senha são enviados apenas pelo formulário seguro abaixo.</p>@endif
</section>
@if($configuration['nfce'] || $configuration['nfe'] || $configuration['nfse'])
@include('fiscal.company',['setupFiscal'=>true])
@endif
<form class="panel setup-actions" method="post" action="{{ route('setup.fiscal.continue',['company'=>$tenant->slug]) }}">@csrf<a class="button secondary" href="{{ route('setup',['company'=>$tenant->slug,'step'=>2]) }}">Voltar para operação</a><button class="primary">Conferir fiscal e continuar</button><p class="hint">Para avançar, os módulos fiscais selecionados precisam estar preparados. Se não for usar um módulo agora, desabilite-o na etapa Operação.</p></form>
@elseif($step===4)
@include('setup.team')
@else
<section class="panel"><h2>Revisão da implantação</h2><p>Empresa, operação e equipe serão mantidas nos seus cadastros definitivos. Produtos, serviços, clientes e movimentações serão cadastrados depois.</p><dl><dt>Empresa</dt><dd>{{ $profile?->legal_name }}</dd><dt>CNPJ</dt><dd>{{ $profile?->document }}</dd><dt>Usuários de acesso</dt><dd>{{ $users->count() }}</dd><dt>Responsáveis pelo caixa</dt><dd>{{ $sellers->pluck('name')->implode(', ') ?: 'Cadastre na etapa Equipe' }}</dd></dl>
@if($configuration['nfce'] || $configuration['nfe'] || $configuration['nfse'])<p class="form-note">Emissão fiscal permanece em homologação/preparação; concluir o cadastro não libera produção.</p>@endif
<dl><dt>Operação</dt><dd>{{ ['mixed'=>'Produtos e serviços','products'=>'Somente produtos','services'=>'Somente serviços'][$configuration['catalog_mode']] }}</dd><dt>Estoque</dt><dd>{{ $configuration['inventory']?'Habilitado':'Desabilitado' }}</dd><dt>Comissões</dt><dd>{{ $configuration['commissions']?($configuration['commission_basis']==='cash'?'Caixa':'Competência'):'Desabilitadas' }}</dd></dl>
@if($pending)<h3>Pontos a conferir</h3><ul>@foreach($pending as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('setup.complete',['company'=>$tenant->slug]) }}">@csrf<input type="hidden" name="version" value="{{ $setup?->version??0 }}"><label class="setup-option"><input type="checkbox" name="reviewed" value="1" required> Revisei os dados, usuários e escolhas desta empresa.</label><button class="primary">Concluir implantação e abrir sistema</button></form>
</section>
@endif
@if($audit->isNotEmpty())<details class="panel"><summary>Histórico recente de configuração</summary><ul>@foreach($audit as $event)<li>{{ \Carbon\Carbon::parse($event->created_at)->format('d/m/Y H:i') }} · versão {{ $event->version }} · {{ match($event->action){'completed'=>'Implantação conferida','company_changed'=>'Dados da empresa alterados',default=>'Configuração alterada'} }}</li>@endforeach</ul></details>@endif
@endsection
