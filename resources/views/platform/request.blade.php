@extends('platform.layout')
@section('title','Solicitação de implantação #'.$provisionRequest->id)
@section('subtitle','Acompanhe o pedido sem expor credenciais do cliente.')
@section('content')
<section class="panel"><h2>{{ $provisionRequest->name }}</h2><dl class="detail-grid"><div><dt>Identificação solicitada</dt><dd>{{ $provisionRequest->slug }}</dd></div><div><dt>Situação do pedido</dt><dd>{{ ['pending'=>'Na fila','provisioning'=>'Em implantação','completed'=>'Implantação concluída','failed'=>'Falha na implantação'][$provisionRequest->status]??'Aguardando conferência' }}</dd></div><div><dt>Classificação</dt><dd>{{ $provisionRequest->is_demonstration?'Demonstração':'Empresa cliente' }}</dd></div><div><dt>Administrador inicial</dt><dd>{{ $provisionRequest->admin_name }}</dd></div><div><dt>E-mail do responsável</dt><dd>{{ $provisionRequest->admin_email }}</dd></div><div><dt>Solicitado em</dt><dd>{{ $provisionRequest->created_at->format('d/m/Y H:i') }}</dd></div></dl></section>
@if($provisionRequest->status==='completed' && $provisionRequest->tenant_id)<div class="notice success"><strong>Implantação concluída.</strong><p>Confira o cadastro e a disponibilidade da empresa na Central.@unless($provisionRequest->activation_status) Entregue o acesso inicial ao administrador por canal seguro.@endunless</p><a href="{{ route('platform.companies.show',['id'=>$provisionRequest->tenant_id]) }}">Gerenciar empresa implantada</a></div>@elseif($provisionRequest->status==='failed')<div class="notice error"><strong>A implantação não foi concluída.</strong><p>A equipe responsável deve revisar a solicitação antes de uma nova tentativa. Nenhuma senha ou detalhe interno do ambiente é exibido aqui.</p></div>@else<div class="notice"><strong>Empresa ainda não disponível.</strong><p>O pedido está {{ $provisionRequest->status==='provisioning'?'em processamento':'aguardando processamento' }}. Esta solicitação não cria uma sessão do cliente nem libera acesso automaticamente.</p></div>@endif
@if($provisionRequest->activation_status)
<section class="panel"><h2>Ativação do administrador inicial</h2>
@if($provisionRequest->activation_status === 'sent')
    @if($provisionRequest->activation_expires_at?->isPast())
        <p class="notice">O convite de ativação expirou.@if($companyActive) Reenvie para o administrador definir a própria senha.@endif</p>
    @else
        <p>Convite enviado por e-mail.@if($provisionRequest->activation_expires_at) Válido até {{ $provisionRequest->activation_expires_at->format('d/m/Y H:i') }}.@endif</p>
    @endif
@elseif($provisionRequest->activation_status === 'queued')
    <p>Convite aguardando envio por e-mail.</p>
    @if($provisionRequest->activation_retry_at)<p class="hint">Próxima tentativa a partir de {{ $provisionRequest->activation_retry_at->format('d/m/Y H:i') }}.</p>@endif
@elseif($provisionRequest->activation_status === 'used')
    <p>Conta ativada pelo administrador.</p>
@else
    <p>Aguardando conferência do convite.</p>
@endif
<div class="actions">
    @if($companyActive)<a href="{{ \App\Tenancy\CompanyHosts::url($provisionRequest->slug) }}">Abrir login da empresa</a>@else<span class="hint">O acesso da empresa está suspenso.</span>@endif
    <a href="{{ route('platform.settings.emails.index',['recipient'=>$provisionRequest->admin_email,'type'=>'company_activation']) }}">Consultar tentativas de envio</a>
</div>
@if($provisionRequest->activation_status === 'sent' && $companyActive)
<form method="post" action="{{ route('platform.requests.activation',['id'=>$provisionRequest->id]) }}">@csrf<button class="button secondary">Reenviar convite e invalidar código anterior</button></form>
@endif</section>@endif
<div class="actions">@if(in_array($provisionRequest->status,['pending','provisioning'],true) || $provisionRequest->activation_status === 'queued')<a class="button secondary" href="{{ route('platform.requests.show',['id'=>$provisionRequest->id]) }}">Atualizar situação</a>@endif<a href="{{ route('platform.index') }}">Voltar à central</a></div>
@endsection
