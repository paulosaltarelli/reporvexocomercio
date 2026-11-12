@extends('layout')
@section('title','Preços e alertas')
@section('subtitle','Escolha quando a base de custo deve atualizar o preço e confira as sugestões que precisam da sua decisão.')
@section('actions')<a class="button secondary" href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>'groups']) }}">Configurar regras por grupo</a>@endsection
@section('content')
<div class="cards two">
    <div class="card featured"><small>Alertas que precisam de atenção</small><strong>{{ $pendingCount }}</strong><small>Confira a diferença ou revise a configuração indicada.</small></div>
    <div class="card"><h2>Uma escolha para cada produto</h2><p class="hint"><strong>Automático:</strong> recalcula o preço usando a margem efetiva e o custo médio (com estoque) ou de referência (sem estoque).<br><strong>Somente alertas:</strong> mantém o preço cadastrado até você decidir aplicar a sugestão.</p></div>
</div>
<section class="panel">
    <h2>Última revisão de cada cadastro</h2>
    <div class="toolbar no-print"><a class="button {{ $filter==='pending'?'':'secondary' }}" href="{{ route('prices',['company'=>$tenant->slug,'filter'=>'pending']) }}">Pendentes ({{ $pendingCount }})</a><a class="button {{ $filter==='all'?'':'secondary' }}" href="{{ route('prices',['company'=>$tenant->slug,'filter'=>'all']) }}">Todas as revisões</a></div>
    <p class="hint">A margem e a regra podem vir do grupo ou ser definidas no produto, de forma independente. Sem custo informado, o preço atual é preservado.</p>
    <div class="scroll" role="region" aria-label="Revisões de preço" tabindex="0"><table>
        <thead><tr><th>Produto / grupo</th><th>Regra efetiva</th><th class="num">Preço atual (R$)</th><th class="num">Preço calculado (R$)</th><th>Conferência</th><th></th></tr></thead>
        <tbody>
        @forelse($reviews as $review)
            @php($product=$review->product)
            @php($hasSuggestion=$review->state==='ready' && $review->suggested_price!==null)
            @php($hasCost=bccomp((string)$review->cost_snapshot,'0',4)>0)
            @php($different=$hasSuggestion && bccomp((string)$product->sale_price,(string)$review->suggested_price,2)!==0)
            @php($canApply=$review->mode==='alert' && $review->needs_attention && $hasSuggestion && $hasCost && $different && $product->active)
            <tr>
                <td class="product-name"><strong>{{ $product->name }}</strong><small class="hint">{{ $product->sku }} · {{ $product->group->name }}</small><small class="hint">Revisado em {{ $review->updated_at->format('d/m/Y H:i') }}</small>@if(!$product->active)<span class="badge bad">Inativo</span>@endif</td>
                <td><span class="badge {{ $review->mode==='automatic'?'':'warn' }}">{{ $review->mode==='automatic'?'Automático':'Somente alertas' }}</span><small class="hint">{{ $product->price_policy===null?'Herdada do grupo':'Definida no produto' }}</small></td>
                <td class="num">{{ number_format($product->sale_price,2,',','.') }}</td>
                <td class="num">@if($hasSuggestion)<strong>{{ number_format($review->suggested_price,2,',','.') }}</strong>@else<span class="muted">—</span>@endif<small class="hint">{{ ($review->cost_source??'average_cost')==='reference_cost'?'Referência':'Custo médio' }} R$ {{ number_format($review->cost_snapshot,4,',','.') }}<br>Margem {{ number_format($review->margin_snapshot,2,',','.') }}%</small></td>
                <td class="product-name">
                    @if($review->state==='no_cost')<span class="badge">Aguardando custo</span><small class="hint">O preço atual não foi alterado.</small>
                    @elseif($review->state==='overflow')<span class="badge bad">Revisar margem</span><small class="hint">A sugestão ultrapassa o limite de preço.</small>
                    @elseif($review->needs_attention && $different)<span class="badge warn">Preço diferente da sugestão</span>
                    @else<span class="badge">Preço conferido</span>
                    @endif
                </td>
                <td>
                    @if($canApply)
                        <form method="post" action="{{ route('price.apply',['company'=>$tenant->slug,'id'=>$product->id]) }}" onsubmit="return confirm('Alterar o preço de R$ {{ number_format($product->sale_price,2,',','.') }} para R$ {{ number_format($review->suggested_price,2,',','.') }}?');">
                            @csrf
                            <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <input type="hidden" name="expected_price" value="{{ $review->suggested_price }}">
                            <button class="primary price-apply">Aplicar sugerido</button>
                        </form>
                    @else
                        <a href="{{ route('product.edit',['company'=>$tenant->slug,'id'=>$product->id]) }}">Revisar produto</a>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="6" class="empty">{{ $filter==='pending'?'Nenhuma divergência pendente. Consulte todas as revisões para ver os cadastros conferidos ou sem custo.':'As revisões aparecerão ao salvar cadastros, regras ou movimentar estoque.' }}</td></tr>
        @endforelse
        </tbody>
    </table></div>
    {{ $reviews->links() }}
    <p class="hint">Ao aplicar, o servidor confere novamente custo, margem e preço sugerido. Se a sugestão mudou desde a abertura da página, a operação pede uma nova conferência.</p>
</section>
<details class="panel disclosure">
    <summary><span>Últimas alterações de preço</span><small>Histórico para conferência</small></summary>
    <div class="disclosure-content"><div class="scroll" role="region" aria-label="Histórico de alterações de preço" tabindex="0"><table>
        <thead><tr><th>Data</th><th>Produto</th><th class="num">Preço anterior (R$)</th><th class="num">Preço novo (R$)</th><th class="num">Custo usado (R$)</th><th class="num">Margem</th><th>Regra</th><th>Origem</th><th>Responsável</th></tr></thead>
        <tbody>
        @forelse($changes as $change)
            <tr><td>{{ $change->created_at->format('d/m/Y H:i') }}</td><td class="product-name">{{ $change->product->name }}</td><td class="num">{{ number_format($change->before_price,2,',','.') }}</td><td class="num"><strong>{{ number_format($change->after_price,2,',','.') }}</strong></td><td class="num">{{ number_format($change->average_cost,4,',','.') }}<small class="hint">{{ ($change->cost_source??'average_cost')==='reference_cost'?'Referência':'Médio' }}</small></td><td class="num">{{ number_format($change->margin,2,',','.') }}%</td><td>{{ $change->mode==='automatic'?'Automático':'Somente alertas' }}</td><td>{{ match(true){str_starts_with((string)$change->source,'stock:')=>'Movimentação de estoque',str_starts_with((string)$change->source,'group:')=>'Regra do grupo',str_starts_with((string)$change->source,'product:')=>'Cadastro do produto',$change->source==='review:apply'=>'Sugestão aplicada',default=>'Ajuste de preço'} }}</td><td>{{ $change->user?->name??'Não informado' }}</td></tr>
        @empty
            <tr><td colspan="9" class="empty">Nenhuma alteração de preço registrada ainda.</td></tr>
        @endforelse
        </tbody>
    </table></div><p class="hint">O histórico mostra alterações registradas por estas regras. Vendas e orçamentos anteriores mantêm os valores registrados na ocasião.</p></div>
</details>
@endsection
