@extends('platform.layout')
@section('title','Gerenciar acessos')
@section('content')
<p><a href="{{ route('platform.companies.show',['id'=>$company->id]) }}">Voltar à ficha de {{ $company->name }}</a></p>
<section class="panel">
    <h2>Implantação de {{ $company->name }}</h2>
    <p>O vendedor recebe por e-mail um link de uso único, válido por 24 horas, e escolhe sua senha. Após o aceite, o acesso continua enquanto estiver habilitado nesta loja.</p>
    <p><strong>Login da loja</strong> <a href="{{ \App\Tenancy\CompanyHosts::url($company->slug) }}" target="_blank" rel="noopener noreferrer">{{ \App\Tenancy\CompanyHosts::url($company->slug) }}</a></p>
    @if($company->active)
    <p><strong>Situação da implantação</strong> {{ $open ? 'Em andamento' : 'Concluída' }}</p>
    <form method="post" action="{{ route('platform.company-access.create',['id'=>$company->id]) }}">
        @csrf
        <div class="form-grid">
            <div class="field"><label for="invite-name">Nome do vendedor implantador</label><input id="invite-name" name="name" maxlength="180" required value="{{ old('name') }}"></div>
            <div class="field"><label for="invite-email">E-mail do vendedor</label><input id="invite-email" name="email" type="email" maxlength="180" required value="{{ old('email') }}"></div>
        </div>
        <div class="field"><label for="invite-profile">Perfil nesta loja</label>
            <select name="access_profile" id="invite-profile">
                @foreach(\App\Platform\InstallerInvitation::PROFILES as $key=>$label)
                    <option value="{{ $key }}" @selected(old('access_profile','installer')===$key)>{{ $label }}</option>
                @endforeach
            </select>
            <small class="hint">Implantação permite configurar e, após a conclusão, consultar o resumo. DBA da loja permite administrar toda a operação desta empresa.</small>
        </div>
        @include('platform.accesses.mfa',['fieldId'=>'invite-mfa'])
        <button class="button">Enviar convite</button>
    </form>
    @else
        <p>A empresa está suspensa.</p>
    @endif
</section>
<section class="panel">
    <h2>Vendedores implantadores</h2>
    @if($invitations->isEmpty())
        <p>Nenhum convite registrado para esta empresa.</p>
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
                        <form method="post" action="{{ route('platform.company-access.update',['id'=>$company->id,'invitation'=>$invite->id]) }}">
                            @csrf<input type="hidden" name="expected_version" value="{{ $invite->version }}">
                            <div class="field"><label for="profile-{{ $invite->id }}">Perfil de {{ $invite->name }}</label>
                                <select name="access_profile" id="profile-{{ $invite->id }}">
                                    @foreach(\App\Platform\InstallerInvitation::PROFILES as $key=>$label)
                                        <option value="{{ $key }}" @selected($invite->access_profile===$key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <input type="hidden" name="enabled" value="0">
                            <label class="checkbox-line"><input type="checkbox" name="enabled" value="1" @checked($invite->status==='accepted' && $activeUsers->get($invite->user_id)===$invite->email)> Acesso habilitado</label>
                            @include('platform.accesses.mfa',['fieldId'=>'grant-'.$invite->id])
                            <button class="button secondary">Salvar acesso</button>
                        </form>
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
