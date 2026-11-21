@extends('operations.layout')
@section('title','Autorizações do caixa')
@section('subtitle','Confira os pedidos dos operadores antes de autorizar uma alteração na venda.')
@section('content')
<section class="operation-panel">
    <div class="operation-heading"><h2>Solicitações</h2><a class="button secondary" href="{{ request()->fullUrl() }}">Atualizar</a></div>
    @forelse($approvals as $approval)
    <article class="operation-approval">
        <div class="operation-line-top"><strong>Venda #{{ $approval->cart_id }} · {{ ['remove'=>'Retirar item','reduce'=>'Reduzir quantidade','discount'=>'Conceder desconto','cancel'=>'Cancelar venda'][$approval->action]??$approval->action }}</strong><span class="operation-badge">{{ $approval->expires_at?->isPast() && in_array($approval->status,['pending','approved'],true) ? 'Expirada' : (['pending'=>'Aguardando','approved'=>'Autorizada','rejected'=>'Não autorizada','used'=>'Aplicada','expired'=>'Expirada'][$approval->status]??$approval->status) }}</span></div>
        @if(isset($approval->payload['item_snapshot']))<p><strong>{{ $approval->payload['item_snapshot']['description'] }}</strong> · {{ $approval->payload['item_snapshot']['quantity'] }} {{ $approval->payload['item_snapshot']['unit']??'' }} · R$ {{ number_format($approval->payload['item_snapshot']['total'],2,',','.') }}</p>@endif
        <p>{{ $approval->requester?->name }} · {{ $approval->reason }}</p>
        @if(isset($approval->payload['quantity']))<p class="hint">Nova quantidade: {{ $approval->payload['quantity'] }}</p>@endif
        @if(isset($approval->payload['discount_percent']))<p class="hint">Desconto solicitado: {{ $approval->payload['discount_percent'] }}%</p>@endif
        <small class="hint">Válida até {{ $approval->expires_at?->format('d/m H:i') }}</small>
        @if($approval->status==='pending' && !$approval->expires_at?->isPast())
        <form method="post" action="{{ route('supermarket.approvals.decide',['company'=>$tenant->slug,'approval'=>$approval->id]) }}">
            @csrf
            <div class="field"><label for="approval-reason-{{ $approval->id }}">Justificativa da decisão</label><input id="approval-reason-{{ $approval->id }}" name="reason" minlength="3" maxlength="1000" required></div>
            <div class="actions"><button class="button" name="decision" value="approve">Autorizar</button><button class="button secondary" name="decision" value="reject">Não autorizar</button></div>
        </form>
        @elseif($approval->decision_reason)<p class="hint">Decisão: {{ $approval->decision_reason }}</p>@endif
    </article>
    @empty<div class="operation-empty">Nenhuma solicitação de autorização neste momento.</div>@endforelse
    {{ $approvals->links() }}
</section>
@endsection
