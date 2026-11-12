@extends('layout')
@section('title','Orçamentos e ordens de serviço')
@section('subtitle','Do planejamento à venda, com vendedor vinculado e sem baixa antecipada de estoque.')
@section('actions')<div class="actions" style="margin:0"><a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'quote']) }}">+ Orçamento</a><a class="button" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'work_order']) }}">+ Ordem de serviço</a></div>@endsection
@section('content')
<section class="panel">
    <form class="toolbar seller-list-filter" method="get"><div><label for="documents-filter-seller">Vendedor</label><select id="documents-filter-seller" name="salesperson_id"><option value="">Todos os vendedores</option>@foreach($sellers as $seller)<option value="{{ $seller->id }}" @selected((string)$sellerId===(string)$seller->id)>{{ $seller->name }}{{ $seller->active?'':' · inativo' }}</option>@endforeach</select></div><button class="primary">Filtrar documentos</button>@if($sellerId)<a href="{{ route('documents',['company'=>$tenant->slug]) }}">Limpar filtro</a>@endif</form>
    <div class="scroll"><table><thead><tr><th>Número</th><th>Tipo</th><th>Cliente</th><th>Vendedor creditado</th><th>Etapa</th><th class="num">Total</th><th></th></tr></thead><tbody>
    @forelse($documents as $document)
        <tr><td>#{{ $document->id }}</td><td>{{ $document->type==='quote'?'Orçamento':'Ordem de serviço' }}</td><td class="product-name">{{ $document->customer?->name??'Não informado' }}</td><td class="product-name">{{ $document->seller_name??'Não informado' }}</td><td><span class="badge">{{ ['draft'=>'Aberto','approved'=>'Aprovado','in_progress'=>'Em execução','completed'=>'Concluído','invoiced'=>'Venda gerada','cancelled'=>'Cancelado'][$document->status] }}</span></td><td class="num">R$ {{ number_format($document->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$document->id]) }}">Abrir</a></td></tr>
    @empty
        <tr><td colspan="7" class="empty">Nenhum documento encontrado para este filtro.</td></tr>
    @endforelse
    </tbody></table></div>{{ $documents->links() }}
</section>
@endsection
