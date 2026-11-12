
@extends('layout')
@section('title','Orçamentos e ordens de serviço')
@section('subtitle','Do planejamento à venda, com etapas claras e sem baixa antecipada de estoque.')
@section('actions')<div class="actions" style="margin:0"><a class="button secondary" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'quote']) }}">+ Orçamento</a><a class="button" href="{{ route('document.new',['company'=>$tenant->slug,'type'=>'work_order']) }}">+ Ordem de serviço</a></div>
@endsection
@section('content')<section class="panel"><div class="scroll"><table><thead><tr><th>Número</th><th>Tipo</th><th>Cliente</th><th>Etapa</th><th class="num">Total</th><th></th></tr></thead><tbody>
@forelse($documents as $d)<tr><td>#{{ $d->id }}</td><td>{{ $d->type==='quote'?'Orçamento':'Ordem de serviço' }}</td><td>{{ $d->customer?->name??'Não informado' }}</td><td><span class="badge">{{ ['draft'=>'Aberto','approved'=>'Aprovado','in_progress'=>'Em execução','completed'=>'Concluído','invoiced'=>'Venda gerada','cancelled'=>'Cancelado'][$d->status] }}</span></td><td class="num">R$ {{ number_format($d->total,2,',','.') }}</td><td><a href="{{ route('document.show',['company'=>$tenant->slug,'id'=>$d->id]) }}">Abrir</a></td></tr>
@empty<tr><td colspan="6" class="empty">Seus orçamentos e ordens de serviço aparecerão aqui.</td></tr>
@endforelse</tbody></table></div>{{ $documents->links() }}</section>
@endsection
