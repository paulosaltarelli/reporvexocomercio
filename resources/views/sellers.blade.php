@extends('layout')
@section('title','Vendedores')
@section('subtitle','Cadastre quem recebe o crédito pelas vendas, com ou sem acesso ao sistema. O histórico permanece mesmo após desativar o perfil.')
@section('content')
<details class="panel disclosure" @if($editing || $errors->any() || $rows->isEmpty()) open @endif>
    <summary><span>{{ $editing?'Editar vendedor: '.$editing->name:'+ Novo vendedor' }}</span><small>Vendedor e usuário de acesso são cadastros diferentes</small></summary>
    <form class="disclosure-content" method="post" action="{{ route('seller.save',['company'=>$tenant->slug]) }}">
        @csrf
        <input type="hidden" name="id" value="{{ old('id',$editing?->id) }}">
        <div class="form-grid">
            <div class="field"><label for="salesperson-name">Nome do vendedor</label><input id="salesperson-name" name="name" value="{{ old('name',$editing?->name) }}" maxlength="180" required autocomplete="name"></div>
            <div class="field"><label for="salesperson-phone">Telefone (opcional)</label><input id="salesperson-phone" name="phone" type="tel" value="{{ old('phone',$editing?->phone) }}" maxlength="30" autocomplete="tel"></div>
            <div class="field"><label for="salesperson-email">E-mail de contato (opcional)</label><input id="salesperson-email" name="email" type="email" value="{{ old('email',$editing?->email) }}" maxlength="180" autocomplete="email" autocapitalize="none" spellcheck="false"></div>
            <div class="field"><input type="hidden" name="active" value="0"><label class="checkbox-line"><input name="active" type="checkbox" value="1" @checked(old('active',$editing?->active??true))> Vendedor ativo</label><small class="hint">Inativo não recebe novas atribuições. As vendas e os nomes já registrados são preservados.</small></div>
        </div>
        <details class="disclosure compact" style="margin-top:20px" @if($errors->hasAny(['user_id','notes'])) open @endif>
            <summary>Vincular login e registrar observações (opcional)</summary>
            <div class="form-grid disclosure-content">
                <div class="field"><label for="salesperson-user">Usuário de acesso vinculado</label><select id="salesperson-user" name="user_id"><option value="">Sem login · apenas vendedor</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected((string)old('user_id',$editing?->user_id)===(string)$user->id) @disabled(!$user->active && (int)($editing?->user_id??0)!==$user->id)>{{ $user->name }} · {{ $user->email }}{{ $user->active?'':' · login inativo' }}</option>@endforeach</select><small class="hint">O vínculo identifica o vendedor ao usar um login existente. Não cria senha nem altera permissões. Vendedores sem login podem ser escolhidos por administradores.</small></div>
                <div class="field full"><label for="salesperson-notes">Observações</label><textarea id="salesperson-notes" name="notes" maxlength="1000">{{ old('notes',$editing?->notes) }}</textarea></div>
            </div>
        </details>
        <div class="actions"><button class="primary">{{ $editing?'Salvar vendedor':'Criar vendedor' }}</button>@if($editing)<a href="{{ route('sellers',['company'=>$tenant->slug]) }}">Cancelar edição</a>@endif</div>
    </form>
</details>
<section class="panel">
    <h2>Equipe de vendas</h2>
    <div class="scroll"><table><thead><tr><th>Vendedor</th><th>Contato</th><th>Login vinculado</th><th>Situação</th><th></th></tr></thead><tbody>
    @forelse($rows as $seller)
        @php($linkedUser=$seller->user_id?$users->firstWhere('id',$seller->user_id):null)
        <tr><td class="product-name"><strong>{{ $seller->name }}</strong></td><td class="product-name">{{ $seller->email??'—' }}@if($seller->phone)<small class="hint">{{ $seller->phone }}</small>@endif</td><td class="product-name">@if($linkedUser){{ $linkedUser->name }}<small class="hint">{{ $linkedUser->email }}{{ $linkedUser->active?'':' · login inativo' }}</small>@else<span class="muted">Sem login</span>@endif</td><td><span class="badge {{ $seller->active?'':'bad' }}">{{ $seller->active?'Ativo':'Inativo' }}</span></td><td><a href="{{ route('sellers',['company'=>$tenant->slug,'edit'=>$seller->id,'page'=>$rows->currentPage()]) }}">Editar</a></td></tr>
    @empty
        <tr><td colspan="5" class="empty">Nenhum vendedor cadastrado. Um perfil de vendedor pode existir sem criar usuário de acesso.</td></tr>
    @endforelse
    </tbody></table></div>
    {{ $rows->links() }}
    <p class="hint">Renomear ou desativar o perfil não troca o vendedor nas vendas concluídas. A comissão continua usando as regras e os nomes guardados na confirmação.</p>
</section>
@endsection
