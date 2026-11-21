@extends('layout')
@section('title','Perfis e permissões')
@section('subtitle','Defina o que cada perfil pode consultar e executar. As mesmas regras protegem menus, botões e acessos diretos.')
@section('actions')<a class="button secondary" href="{{ route('users',['company'=>$tenant->slug]) }}">Vincular usuários</a>@endsection
@section('content')
@php($editing=$selectedId!==null)
@php($selected=$editing?$accessState['profiles'][$selectedId]:($presetId ? $presets[$presetId] : ['name'=>'','permissions'=>[]]))
@php($selected['permissions']=\App\Access\Permissions::expand($selected['permissions']))
<section class="panel"><h2>Perfis de operação prontos</h2><p>Escolha um perfil para revisar e salvar. Depois, vincule os usuários em Usuários e permissões.</p><div class="actions">
@foreach($presets as $presetKey=>$preset)
<a class="button {{ $presetId===$presetKey?'primary':'secondary' }}" href="{{ route('access.profiles',['company'=>$tenant->slug,'preset'=>$presetKey]) }}">{{ $preset['name'] }}</a>
@endforeach
</div><p class="hint">Esses perfis abrem diretamente o painel da função. Cancelamentos, descontos especiais e funções gerenciais podem ser liberados separadamente pelo administrador.</p></section>
<div class="access-layout">
<section class="panel access-list"><h2>Perfis desta empresa</h2><a class="button secondary" href="{{ route('access.profiles',['company'=>$tenant->slug]) }}">+ Novo perfil</a><div class="access-admin-card"><strong>Administrador</strong><small>Acesso completo. Usuários e perfis são gerenciados somente por administradores.</small></div>
@foreach($accessState['profiles'] as $id=>$profile)
@php($members=$users->filter(fn($user)=>$user->role==='operator'&&$profileService->profileId($user->id)===$id))
<a class="access-profile-link {{ $selectedId===$id?'active':'' }}" href="{{ route('access.profiles',['company'=>$tenant->slug,'profile'=>$id]) }}"><strong>{{ $profile['name'] }}</strong><small>{{ $members->count() }} usuário(s) · {{ count($profile['permissions']) }} função(ões)</small></a>
@endforeach
</section>
<section class="panel access-editor"><h2>{{ $editing?'Editar perfil':'Novo perfil' }}</h2><form method="post" action="{{ route('access.profiles.save',['company'=>$tenant->slug]) }}">
@csrf<input type="hidden" name="permissions_present" value="1"><input type="hidden" name="version" value="{{ $accessState['version'] }}">@if($editing)<input type="hidden" name="profile_id" value="{{ $selectedId }}">@endif
<div class="field"><label for="access-name">Nome do perfil</label><input id="access-name" name="name" maxlength="80" required value="{{ old('name',$selected['name']) }}" placeholder="Ex.: Caixa, Estoquista, Financeiro"></div>
<div class="field"><label for="access-discount-limit">Limite de desconto do supervisor (%)</label><input id="access-discount-limit" name="max_discount_percent" type="number" min="0" max="100" step="0.01" value="{{ old('max_discount_percent',$selected['max_discount_percent']??'100') }}"><small class="hint">Usado nas autorizações do supermercado. As regras dos produtos também são respeitadas. Este limite não concede permissão para autorizar ajustes.</small></div>
<p class="form-note">Marque somente as funções necessárias. Uma operação pode exigir consultas relacionadas: essas dependências também são liberadas ao salvar. Custos e margens ficam ocultos quando não autorizados.</p>
@php($checkedPermissions=(array)(session()->hasOldInput('permissions_present')?old('permissions',[]):$selected['permissions']))
@foreach($permissionGroups as $group=>$permissions)
<fieldset class="access-permission-group"><legend>{{ $group }}</legend><div class="access-permission-grid">@foreach($permissions as $key=>$label)<label class="access-permission"><input type="checkbox" name="permissions[]" value="{{ $key }}" @checked(in_array($key,$checkedPermissions,true))><span>{{ $label }}@if(isset($dependencies[$key]))<small>Também libera: {{ implode(', ',array_map(fn($dependency)=>\App\Access\Permissions::labels()[$dependency],$dependencies[$key])) }}.</small>@endif</span></label>@endforeach</div></fieldset>
@endforeach
<div class="actions"><button class="primary">Salvar perfil</button><a href="{{ route('users',['company'=>$tenant->slug]) }}">Gerenciar usuários</a></div>
</form>
@if($editing)
<details class="access-preview" open><summary>O que este perfil pode ver e fazer</summary><p class="hint">Prévia de permissões efetivas salvas, sem entrar como outro usuário. Recursos desabilitados na empresa continuam indisponíveis para novas operações; históricos são preservados.</p><h3>Menus disponíveis para este perfil</h3><div class="access-preview-menus">@foreach(\App\Access\Permissions::NAVIGATION as [$menuLabel,$menuRoute,$menuParameters,$permission])@if(in_array($permission,$selected['permissions'],true))<span class="badge">{{ $menuLabel }}</span>@endif @endforeach</div><div class="access-preview-columns"><div><h3>Liberado</h3><ul>@forelse(\App\Access\Permissions::labels() as $key=>$label)@if(in_array($key,$selected['permissions'],true))<li>{{ $label }}</li>@endif @empty<li>Nenhuma função.</li>@endforelse</ul>@if(!$selected['permissions'])<p class="muted">Sem funções operacionais. O usuário poderá apenas acessar sua conta e sair.</p>@endif</div><div><h3>Não liberado</h3><ul>@foreach(\App\Access\Permissions::labels() as $key=>$label)@if(!in_array($key,$selected['permissions'],true))<li>{{ $label }}</li>@endif @endforeach<li>Gerenciar usuários e perfis</li><li>Alterar dados e configuração da empresa</li></ul></div></div>
<h3>Usuários vinculados</h3><ul>@forelse($users->filter(fn($user)=>$user->role==='operator'&&$profileService->profileId($user->id)===$selectedId) as $member)<li>{{ $member->name }} · {{ $member->active?'ativo':'inativo' }}</li>@empty<li>Nenhum usuário vinculado.</li>@endforelse</ul></details>
@if($selectedId!=='operator')<form method="post" action="{{ route('access.profiles.delete',['company'=>$tenant->slug]) }}" class="actions">@csrf<input type="hidden" name="profile_id" value="{{ $selectedId }}"><input type="hidden" name="version" value="{{ $accessState['version'] }}"><button class="text-button">Excluir perfil sem usuários vinculados</button></form>@endif
@endif
</section></div>
@endsection

@push('scripts')
<script id="permission-dependencies" type="application/json">@json($dependencies)</script>
<script src="/access-profiles.js?v={{ filemtime(public_path('access-profiles.js')) }}"></script>
@endpush
