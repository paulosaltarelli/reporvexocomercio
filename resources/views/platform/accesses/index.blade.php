@extends('platform.layout')
@section('title','Gerenciar acessos')
@section('subtitle',$company->name)
@section('content')
@include('platform.company-navigation')
<div class="central-access-summary"><span class="central-status" data-tone="{{ $company->active ? 'good':'neutral' }}">{{ !$company->active ? 'Empresa suspensa' : ($open ? 'Implantação em andamento' : 'Implantação concluída') }}</span>
@if($company->active)<a href="{{ \App\Tenancy\CompanyHosts::url($company->slug) }}" target="_blank" rel="noopener noreferrer">Login da loja <span aria-hidden="true">↗</span></a>@endif</div>
@if($company->active)
<details class="panel central-disclosure" @if($invitations->isEmpty() || old('email') || old('name')) open @endif>
    <summary>Convidar vendedor</summary>
    <p>O convite vale por 24 horas. Após o aceite, o acesso permanece enquanto estiver habilitado.</p>
    <form method="post" action="{{ route('platform.company-access.create',['id'=>$company->id]) }}">
        @csrf
        <div class="form-grid">
            <div class="field"><label for="invite-name">Nome do vendedor implantador</label><input id="invite-name" name="name" maxlength="180" required value="{{ old('name') }}"></div>
            <div class="field"><label for="invite-email">E-mail do vendedor</label><input id="invite-email" name="email" type="email" maxlength="180" required value="{{ old('email') }}"></div>
        </div>
        <div class="field"><label for="invite-profile">Perfil nesta loja</label>
            <select name="access_profile" id="invite-profile">
                @foreach(\App\Platform\InstallerInvitation::PROFILES as $key=>$label)
                    <option value="{{ $key }}" @selected((old('editing_grant') === null ? old('access_profile','installer') : 'installer')===$key)>{{ $label }}</option>
                @endforeach
            </select>
            <small class="hint">Implantação permite configurar e, após a conclusão, consultar o resumo. DBA da loja permite administrar toda a operação desta empresa.</small>
        </div>
        @include('platform.accesses.mfa',['fieldId'=>'invite-mfa'])
        <button class="button">Enviar convite</button>
    </form>
</details>
@endif
<section class="panel central-list">
    <div class="central-list-heading"><h2>Vendedores implantadores</h2><span class="central-count">{{ $invitations->total() }}</span></div>
    @if($invitations->isEmpty())
        <p class="empty">Nenhum convite registrado para esta empresa.</p>
    @else
        <div class="table-scroll access-list" role="region" aria-label="Vendedores implantadores" tabindex="0"><table>
            <thead><tr><th>Vendedor</th><th>Situação</th><th>Convite</th><th>Ações</th></tr></thead>
            <tbody>@foreach($invitations as $invite)
                <tr><td data-label="Vendedor"><strong>{{ $invite->name }}</strong><br>{{ $invite->email }}</td><td data-label="Situação">{{ $invite->label((bool)$company->active,$activeUsers->get($invite->user_id)===$invite->email) }}<br>{{ \App\Platform\InstallerInvitation::PROFILES[$invite->access_profile] ?? 'Implantação' }}</td>
                    <td data-label="Convite">@if($invite->accepted_at)Aceito em {{ $invite->accepted_at->format('d/m/Y H:i') }}
                        @elseif($invite->delivery_status==='sent' && $invite->expires_at)O link expira em {{ $invite->expires_at->format('d/m/Y H:i') }}
                        @else Criado em {{ $invite->created_at->format('d/m/Y H:i') }} @endif</td>
                    <td data-label="Ações">
                        @if($company->active && $invite->status==='pending')
                        <form method="post" action="{{ route('platform.company-access.resend',['id'=>$company->id,'invitation'=>$invite->id]) }}">
                            @csrf<input type="hidden" name="expected_version" value="{{ $invite->version }}">
                            @include('platform.accesses.mfa',['fieldId'=>'resend-'.$invite->id])
                            <button class="button secondary" aria-label="Reenviar convite para {{ $invite->name }}">Reenviar convite</button>
                        </form>
                        @endif
                        @if($invite->accepted_at && $invite->user_id && $company->active)
                        @php($editingGrant = old('editing_grant') === (string)$invite->id)
                        <details class="central-row-edit" @if($editingGrant) open @endif><summary>Editar acesso</summary>
                        <form method="post" action="{{ route('platform.company-access.update',['id'=>$company->id,'invitation'=>$invite->id]) }}">
                            @csrf<input type="hidden" name="expected_version" value="{{ $invite->version }}">
                            <div class="field"><label for="profile-{{ $invite->id }}">Perfil de {{ $invite->name }}</label>
                                <select name="access_profile" id="profile-{{ $invite->id }}">
                                    @foreach(\App\Platform\InstallerInvitation::PROFILES as $key=>$label)
                                        <option value="{{ $key }}" @selected(($editingGrant ? old('access_profile') : $invite->access_profile)===$key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <input type="hidden" name="enabled" value="0">
                            <label class="checkbox-line"><input type="checkbox" name="enabled" value="1" @checked($editingGrant ? old('enabled') === '1' : ($invite->status==='accepted' && $activeUsers->get($invite->user_id)===$invite->email))> Acesso habilitado</label>
                            @include('platform.accesses.mfa',['fieldId'=>'grant-'.$invite->id])
                            <button class="button secondary">Salvar acesso</button>
                        </form>
                        </details>
                        @elseif($invite->status!=='revoked')
                        <form method="post" action="{{ route('platform.company-access.revoke',['id'=>$company->id,'invitation'=>$invite->id]) }}">
                            @csrf<input type="hidden" name="expected_version" value="{{ $invite->version }}">
                            @include('platform.accesses.mfa',['fieldId'=>'revoke-'.$invite->id])
                            <button class="button secondary" aria-label="Revogar acesso de {{ $invite->name }}">Revogar acesso</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach</tbody>
        </table></div>
        {{ $invitations->links('platform.pagination',['label'=>'Páginas de vendedores']) }}
    @endif
</section>
@endsection
