
@extends('layout')
@section('title',$title)
@section('subtitle','Cadastros compartilhados pelos fluxos de venda e atendimento da sua empresa.')
@section('content')
@php($catalogFields=$kind==='groups'?array_merge($fields,['price_policy'=>'Atualização do preço']):$fields)
@php($visibleFields=array_filter($catalogFields,fn($label,$field)=>$currentUser->role==='admin'||!in_array($field,['cost','margin','price_policy','discount_enabled','max_discount_percent','commission_percent'],true),ARRAY_FILTER_USE_BOTH))
@php($commercialKeys=['discount_enabled','max_discount_percent','commission_percent'])
@php($mainFields=array_diff_key($visibleFields,array_flip($commercialKeys)))
@if($currentUser->role==='admin')<form class="panel" method="post">
@csrf<h2>{{ $editing?'Editar cadastro':'Novo cadastro' }}</h2><input type="hidden" name="id" value="{{ $editing?->id }}"><div class="form-grid">
@foreach($mainFields as $field=>$label)
@if($field==='price_policy')
<div class="field"><label for="price_policy">Atualização do preço</label><select id="price_policy" name="price_policy" required><option value="alert" @selected(old('price_policy',$editing?->price_policy??'alert')==='alert')>Somente alertas · manter preço atual</option><option value="automatic" @selected(old('price_policy',$editing?->price_policy??'alert')==='automatic')>Automático · atualizar pelo custo médio</option></select><small class="hint">Regra padrão para os produtos que herdam a configuração deste grupo.</small></div>
@else
@php($numeric=in_array($field,['margin','price','cost','iss_rate']))<div class="field"><label for="{{ $field }}">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="{{ $numeric?'number':($field==='email'?'email':'text') }}" 
@if($numeric) step="0.01" min="0" 
@endif 
@if(in_array($field,['margin','iss_rate'])) max="{{ $field==='margin'?'99.99':'100' }}" 
@endif value="{{ old($field,$editing?->$field??($numeric?($field==='margin'?30:0):'')) }}" 
@required(in_array($field,['name','margin','price','cost']))></div>
@endif
@endforeach</div>
@if($kind==='groups')
<details class="disclosure compact" style="margin-top:22px" @if($errors->hasAny(['discount_enabled','max_discount_percent','commission_percent'])) open @endif>
    <summary>Descontos e comissões</summary>
    <div class="form-grid disclosure-content">
        <div class="field"><input type="hidden" name="discount_enabled" value="0"><label class="checkbox-line"><input type="checkbox" name="discount_enabled" value="1" @checked(old('discount_enabled',$editing?->discount_enabled??false))> Permitir descontos nos itens deste grupo</label><small class="hint">É a regra padrão. Um produto pode ter sua própria configuração.</small></div>
        <div class="field"><label>Desconto máximo (%)</label><input name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="{{ old('max_discount_percent',$editing?->max_discount_percent??'0') }}" required><small class="hint">Considera também reduzir o preço unitário. Só se aplica quando o desconto estiver permitido.</small></div>
        <div class="field"><label>Comissão sobre a venda líquida (%)</label><input name="commission_percent" type="number" min="0" max="100" step="0.01" value="{{ old('commission_percent',$editing?->commission_percent??'0') }}" required><small class="hint">Zero desativa a comissão. O percentual não representa pagamento ao vendedor.</small></div>
    </div>
    <p class="hint">A venda guarda as regras usadas na confirmação. Alterar os percentuais não recalcula comissões de vendas já concluídas.</p>
</details>
@endif
<div class="actions"><button class="primary">Salvar</button>
@if($editing)<a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind]) }}">Cancelar edição</a>
@endif</div>
@if($kind==='groups')<p class="hint">A margem é sobre o valor final da venda, e não um acréscimo sobre o custo. Exemplo: R$ 60,00 de custo com 40% de margem resulta em R$ 100,00. Ao alterar a regra ou a margem, produtos em modo automático com custo válido têm o preço recalculado. Os demais mantêm o preço e recebem um alerta quando houver diferença. Regras próprias do produto continuam valendo; produtos sem custo não têm o preço zerado.</p>
@endif</form>
@endif
<section class="panel"><div class="scroll"><table><thead><tr>
@foreach($visibleFields as $label)<th>{{ $label }}</th>
@endforeach<th></th></tr></thead><tbody>
@forelse($rows as $row)<tr>
@foreach($visibleFields as $field=>$label)<td>
@if($field==='price_policy')<span class="badge {{ ($row->price_policy??'alert')==='automatic'?'':'warn' }}">{{ ($row->price_policy??'alert')==='automatic'?'Automático':'Somente alertas' }}</span>
@elseif($field==='discount_enabled'){{ $row->discount_enabled?'Permitido':'Bloqueado' }}
@elseif(in_array($field,['max_discount_percent','commission_percent'],true)){{ number_format($row->$field??0,2,',','.') }}%
@else{{ $row->$field??'—' }}
@endif</td>
@endforeach<td>
@if($currentUser->role==='admin')<a href="{{ route('catalog',['company'=>$tenant->slug,'kind'=>$kind,'edit'=>$row->id]) }}">Editar</a>
@endif</td></tr>
@empty<tr><td colspan="{{ count($visibleFields)+1 }}" class="empty">Nenhum cadastro ainda.</td></tr>
@endforelse</tbody></table></div>{{ $rows->links() }}</section>
@endsection
