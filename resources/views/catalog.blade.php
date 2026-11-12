
@extends('layout')
@section('title',$title)
@section('subtitle','Cadastros compartilhados pelos fluxos de venda e atendimento da sua empresa.')
@section('content')
@php($visibleFields=array_filter($fields,fn($label,$field)=>$currentUser->role==='admin'||!in_array($field,['cost','margin'],true),ARRAY_FILTER_USE_BOTH))
@if($currentUser->role==='admin')<form class="panel" method="post">
@csrf<h2>{{ $editing?'Editar cadastro':'Novo cadastro' }}</h2><input type="hidden" name="id" value="{{ $editing?->id }}"><div class="form-grid">
@foreach($visibleFields as $field=>$label)
@php($numeric=in_array($field,['margin','price','cost','iss_rate']))<div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $numeric?'number':($field==='email'?'email':'text') }}" 
@if($numeric) step="0.01" min="0" 
@endif 
@if(in_array($field,['margin','iss_rate'])) max="{{ $field==='margin'?'99.99':'100' }}" 
@endif value="{{ old($field,$editing?->$field??($numeric?($field==='margin'?30:0):'')) }}" 
@required(in_array($field,['name','margin','price','cost']))></div>
@endforeach</div><div class="actions"><button class="primary">Salvar</button>
@if($editing)<a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}">Cancelar edição</a>
@endif</div>
@if($kind==='groups')<p class="hint">A margem é sobre o valor final da venda, e não um acréscimo sobre o custo. Exemplo: R$ 60,00 de custo com 40% de margem resulta em R$ 100,00. Alterar o grupo atualiza as sugestões dos produtos que herdam a margem; não altera preços já cadastrados.</p>
@endif</form>
@endif
<section class="panel"><div class="scroll"><table><thead><tr>
@foreach($visibleFields as $label)<th>{{ $label }}</th>
@endforeach<th></th></tr></thead><tbody>
@forelse($rows as $row)<tr>
@foreach($visibleFields as $field=>$label)<td>{{ $row->$field??'—' }}</td>
@endforeach<td>
@if($currentUser->role==='admin')<a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind,'edit'=>$row->id]) }}">Editar</a>
@endif</td></tr>
@empty<tr><td colspan="{{ count($visibleFields)+1 }}" class="empty">Nenhum cadastro ainda.</td></tr>
@endforelse</tbody></table></div>{{ $rows->links() }}</section>
@endsection
