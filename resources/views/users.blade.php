
@extends('layout')
@section('title','Usuários e acesso')
@section('subtitle','Administradores gerenciam cadastros e estoque. Operadores consultam e registram vendas.')
@section('content')<form class="panel" method="post">
@csrf<h2>Novo usuário</h2><div class="form-grid">
@foreach(['name'=>'Nome','email'=>'E-mail','password'=>'Senha (mínimo 12 caracteres)','password_confirmation'=>'Confirme a senha'] as $field=>$label)<div class="field"><label>{{ $label }}</label><input name="{{ $field }}" type="{{ str_starts_with($field,'password')?'password':($field==='email'?'email':'text') }}" required 
@if(str_starts_with($field,'password')) minlength="12" autocomplete="new-password" 
@else value="{{ old($field) }}" 
@endif></div>
@endforeach<div class="field"><label>Perfil</label><select name="role"><option value="operator">Operador</option><option value="admin">Administrador</option></select></div></div><div class="actions"><button class="primary">Criar usuário</button></div></form><section class="panel"><table><thead><tr><th>Nome</th><th>E-mail</th><th>Perfil</th></tr></thead><tbody>
@foreach($users as $u)<tr><td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->role==='admin'?'Administrador':'Operador' }}</td></tr>
@endforeach</tbody></table></section>
@endsection
