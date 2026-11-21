@if(isset($approvals) && $approvals->isNotEmpty())
<section class="operation-panel">
    <div class="operation-heading"><h2>Autorizações desta venda</h2><a class="button secondary" href="{{ request()->fullUrl() }}">Atualizar</a></div>
    @foreach($approvals as $approval)
    <article class="operation-approval">
        <div class="operation-line-top"><strong>{{ ['remove'=>'Retirada de item','reduce'=>'Redução de quantidade','discount'=>'Desconto','cancel'=>'Cancelamento'][$approval->action]??$approval->action }}</strong><span class="operation-badge">{{ $approval->expires_at?->isPast() && in_array($approval->status,['pending','approved'],true) ? 'Expirada' : (['pending'=>'Aguardando supervisor','approved'=>'Autorizada','rejected'=>'Não autorizada','used'=>'Aplicada','expired'=>'Expirada'][$approval->status]??$approval->status) }}</span></div>
        <p class="hint">{{ $approval->reason }}</p>
        @if($approval->decision_reason)<p class="hint">{{ $approval->decision_reason }}</p>@endif
        @if($approval->status==='approved' && !$approval->expires_at?->isPast() && (int)$approval->cart_version===(int)$cart->version)
        <form method="post" action="{{ route('supermarket.approvals.apply',['company'=>$tenant->slug,'cart'=>$cart->id,'approval'=>$approval->id]) }}">@csrf<input type="hidden" name="version" value="{{ $cart->version }}"><button class="button secondary">Aplicar alteração autorizada</button></form>
        @elseif($approval->status==='approved' && $approval->expires_at?->isPast())<p class="hint">Esta autorização expirou. Faça uma nova solicitação.</p>
        @elseif($approval->status==='approved')<p class="hint">A venda mudou. Solicite uma nova autorização para conferir a alteração atual.</p>@endif
    </article>
    @endforeach
</section>
@endif
