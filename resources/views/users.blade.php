@extends('layout')
@section('title','Usuários e acesso')
@section('subtitle','Defina quem pode operar e quem pode administrar os cadastros, custos e financeiro da empresa.')
@section('content')
<details class="panel disclosure" @if(old('name') && !old('editing_user_id')) open @endif>
    <summary><span>+ Novo usuário</span><small>Acesso exclusivo a esta empresa</small></summary>
    <form method="post" class="disclosure-content">
        @csrf
        <div class="form-grid">
            <div class="field"><label>Nome</label><input name="name" value="{{ old('editing_user_id')?'':old('name') }}" maxlength="180" required autocomplete="name"></div>
            <div class="field"><label>E-mail</label><input name="email" type="email" value="{{ old('editing_user_id')?'':old('email') }}" maxlength="180" required autocomplete="off" autocapitalize="none" spellcheck="false"></div>
            <div class="field"><label>Perfil</label><select name="role"><option value="operator">Operador</option><option value="admin" @selected(!old('editing_user_id')&&old('role')==='admin')>Administrador</option></select></div>
            <div class="field"><label>Senha inicial</label><input name="password" type="password" minlength="12" maxlength="128" required autocomplete="new-password"><small class="hint">Use no mínimo 12 caracteres.</small></div>
            <div class="field"><label>Confirme a senha inicial</label><input name="password_confirmation" type="password" minlength="12" maxlength="128" required autocomplete="new-password"></div>
        </div>
        <div class="actions"><button class="primary">Criar usuário</button></div>
    </form>
</details>
<section class="panel">
    <h2>Equipe da empresa</h2>
    <p class="hint">Operadores registram vendas e consultam catálogo e saldos. Administradores também gerenciam custos, compras, financeiro e acessos.</p>
    <div class="scroll"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Situação</th><th></th></tr></thead><tbody>
    @foreach($users as $user)
        @php($editingUser=(string)old('editing_user_id')===(string)$user->id)
        <tr><td><strong>{{ $user->name }}</strong>@if($user->id===$currentUser->id)<small class="hint">Seu acesso</small>@endif</td><td>{{ $user->email }}</td><td>{{ $user->role==='admin'?'Administrador':'Operador' }}</td><td><span class="badge {{ $user->active?'':'bad' }}">{{ $user->active?'Ativo':'Inativo' }}</span></td><td><a href="#user-{{ $user->id }}" data-open-disclosure="user-{{ $user->id }}">Editar acesso</a></td></tr>
        <tr class="entry-payment-row"><td colspan="5">
            <details class="disclosure compact payment-disclosure" id="user-{{ $user->id }}" @if($editingUser) open @endif>
                <summary>Editar {{ $user->name }}</summary>
                <form method="post" action="{{ route('user.update',['company'=>$tenant->slug,'id'=>$user->id]) }}" class="disclosure-content">
                    @csrf
                    <input type="hidden" name="editing_user_id" value="{{ $user->id }}">
                    <div class="form-grid">
                        <div class="field"><label>Nome</label><input name="name" value="{{ $editingUser?old('name',$user->name):$user->name }}" maxlength="180" required></div>
                        <div class="field"><label>E-mail</label><input type="email" name="email" value="{{ $editingUser?old('email',$user->email):$user->email }}" maxlength="180" required autocapitalize="none" spellcheck="false"></div>
                        <div class="field"><label>Perfil</label><select name="role"><option value="operator" @selected(($editingUser?old('role',$user->role):$user->role)==='operator')>Operador</option><option value="admin" @selected(($editingUser?old('role',$user->role):$user->role)==='admin')>Administrador</option></select></div>
                        <div class="field"><input type="hidden" name="active" value="0"><label class="checkbox-line"><input type="checkbox" name="active" value="1" @checked($editingUser?(bool)old('active'):$user->active)> Acesso ativo</label><small class="hint">Desative para bloquear o acesso sem apagar o histórico.</small></div>
                    </div>
                    <details class="disclosure compact"><summary>Definir nova senha para este usuário</summary><div class="form-grid disclosure-content"><div class="field"><label>Nova senha (opcional)</label><input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password"><small class="hint">Em branco mantém a senha atual.</small></div><div class="field"><label>Confirme a nova senha</label><input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password"></div></div></details>
                    <div class="actions"><button class="primary">Salvar acesso</button></div>
                </form>
            </details>
        </td></tr>
    @endforeach
    </tbody></table></div>
</section>
@endsection
