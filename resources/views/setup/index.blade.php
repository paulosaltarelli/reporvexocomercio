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
<section class="panel"><h2>Identidade da empresa</h2>
<p>Os dados e a logo de Minha empresa são usados nos relatórios. Confira também endereço, registros e regime tributário antes de configurar o fiscal.</p>
<dl><dt>Razão social</dt><dd>{{ $profile?->legal_name ?: 'Não preenchida' }}</dd><dt>CNPJ</dt><dd>{{ $profile?->document ?: 'Não preenchido' }}</dd><dt>Contato</dt><dd>{{ $profile?->email ?: 'Não preenchido' }}</dd></dl>
<div class="setup-actions"><a class="button primary" href="{{ route('business',['company'=>$tenant->slug]) }}">Preencher dados e enviar logo</a><a class="button secondary" href="{{ route('setup',['company'=>$tenant->slug,'step'=>2]) }}">Continuar para operação</a></div>
</section>
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
<section class="panel"><h2>Preparação fiscal</h2>
<p>NFC-e e NF-e permanecem em homologação. NFS-e possui preparação local de DPS; a transmissão ainda não está disponível.</p>
<ul>@foreach(['nfce'=>'NFC-e','nfe'=>'NF-e','nfse'=>'NFS-e'] as $key=>$label)<li>{{ $label }}: <strong>{{ $configuration[$key]?'habilitada para configurar':'desabilitada' }}</strong></li>@endforeach</ul>
@if($configuration['nfce'] || $configuration['nfe'] || $configuration['nfse'])
<p>Cadastre o certificado PFX e a senha exclusivamente na tela segura da empresa. Não envie pelo chat. Habilitar o recurso não confirma credenciamento ou autorização fiscal. Desabilitar bloqueia novas emissões; documentos já enfileirados continuam seu processamento, e consultas ou cancelamentos permanecem disponíveis.</p>
<div class="setup-actions"><a class="button primary" href="{{ route('business',['company'=>$tenant->slug]) }}">Configurar certificado e emitente</a>@if($configuration['nfse'])<a class="button secondary" href="{{ route('nfse.index',['company'=>$tenant->slug]) }}">Preparação de NFS-e</a>@endif</div>
@else
<p>Esta operação não utilizará emissão fiscal pelo sistema. Nenhum certificado é necessário para concluir este cadastro.</p>
@endif
<a class="button secondary" href="{{ route('setup',['company'=>$tenant->slug,'step'=>4]) }}">Continuar para equipe</a>
</section>
@elseif($step===4)
<section class="panel"><h2>Usuários e vendedores</h2>
<p>Cadastre quem terá acesso e confira os perfis de administrador e operador. O vendedor continua vinculado à venda e ao orçamento mesmo sem comissões.</p>
<p>Comissões: <strong>{{ $configuration['commissions']?'habilitadas':'desabilitadas' }}</strong>. Regime das próximas vendas: <strong>{{ $configuration['commission_basis']==='cash'?'caixa':'competência' }}</strong>.</p>
<div class="setup-actions"><a class="button primary" href="{{ route('users',['company'=>$tenant->slug]) }}">Configurar usuários</a><a class="button secondary" href="{{ route('sellers',['company'=>$tenant->slug]) }}">Configurar vendedores</a><a class="button secondary" href="{{ route('setup',['company'=>$tenant->slug,'step'=>5]) }}">Revisar implantação</a></div>
</section>
@else
<section class="panel"><h2>Revisão da implantação</h2>
@if($configuration['nfce'] || $configuration['nfe'] || $configuration['nfse'])<p class="form-note">Emissão fiscal permanece em homologação/preparação; concluir o cadastro não libera produção.</p>@endif
<dl><dt>Operação</dt><dd>{{ ['mixed'=>'Produtos e serviços','products'=>'Somente produtos','services'=>'Somente serviços'][$configuration['catalog_mode']] }}</dd><dt>Estoque</dt><dd>{{ $configuration['inventory']?'Habilitado':'Desabilitado' }}</dd><dt>Comissões</dt><dd>{{ $configuration['commissions']?($configuration['commission_basis']==='cash'?'Caixa':'Competência'):'Desabilitadas' }}</dd></dl>
@if($pending)<h3>Pontos a conferir</h3><ul>@foreach($pending as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
<form method="post" action="{{ route('setup.complete',['company'=>$tenant->slug]) }}">@csrf<input type="hidden" name="version" value="{{ $setup?->version??0 }}"><label class="setup-option"><input type="checkbox" name="reviewed" value="1" required> Revisei os dados, usuários e escolhas desta empresa.</label><button class="primary">Concluir conferência</button></form>
</section>
@endif
@if($audit->isNotEmpty())<details class="panel"><summary>Histórico recente de configuração</summary><ul>@foreach($audit as $event)<li>{{ \Carbon\Carbon::parse($event->created_at)->format('d/m/Y H:i') }} · versão {{ $event->version }} · {{ match($event->action){'completed'=>'Implantação conferida','company_changed'=>'Dados da empresa alterados',default=>'Configuração alterada'} }}</li>@endforeach</ul></details>@endif
@endsection
