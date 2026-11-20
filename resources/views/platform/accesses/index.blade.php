@extends('platform.layout')
@section('title','Gerenciar acessos')
@section('content')
<p><a href="{{ route('platform.companies.show',['id'=>$company->id]) }}">Voltar à ficha de {{ $company->name }}</a></p>
<section class="panel">
    <h2>Implantação de {{ $company->name }}</h2>
    <p>O vendedor recebe um convite no próprio e-mail e escolhe sua senha. O acesso permite configurar somente esta loja e termina ao concluir a implantação.</p>
    <p><strong>Login da loja</strong> <a href="{{ \App\Tenancy\CompanyHosts::url($company->slug) }}" target="_blank" rel="noopener noreferrer">{{ \App\Tenancy\CompanyHosts::url($company->slug) }}</a></p>
    @if($open)
    <form method="post" action="{{ route('platform.company-access.create',['id'=>$company->id]) }}">
        @csrf
        <div class="form-grid">
            <div class="field"><label for="invite-name">Nome do vendedor implantador</label><input id="invite-name" name="name" maxlength="180" required value="{{ old('name') }}"></div>
            <div class="field"><label for="invite-email">E-mail do vendedor</label><input id="invite-email" name="email" type="email" maxlength="180" required value="{{ old('email') }}"></div>
        </div>
        @include('platform.accesses.mfa',['fieldId'=>'invite-mfa'])
        <button class="button">Enviar convite</button>
    </form>
    @else
        <p>{{ $company->active?'A implantação foi concluída.':'A empresa está suspensa.' }}</p>
    @endif
</section>
<section class="panel">
    <h2>Vendedores implantadores</h2>
    @if($invitations->isEmpty())
        <p>Nenhum convite registrado para esta empresa.</p>
    @else
        <div class="table-scroll"><table>
            <thead><tr><th>Vendedor</th><th>Situação</th><th>Convite</th><th>Ações</th></tr></thead>
            <tbody>@foreach($invitations as $invite)
                <tr><td>{{ $invite->name }}<br>{{ $invite->email }}</td><td>{{ $invite->label((bool)$company->active,$open) }}</td>
                    <td>@if($invite->accepted_at)Aceito em {{ $invite->accepted_at->format('d/m/Y H:i') }}
                        @elseif($invite->delivery_status==='sent' && $invite->expires_at)Expiração {{ $invite->expires_at->format('d/m/Y H:i') }}
                        @else Criado em {{ $invite->created_at->format('d/m/Y H:i') }} @endif</td>
                    <td>
                        @if($open && $invite->status==='pending')
                        <form method="post" action="{{ route('platform.company-access.resend',['id'=>$company->id,'invitation'=>$invite->id]) }}">
                            @csrf<input type="hidden" name="expected_version" value="{{ $invite->version }}">
                            @include('platform.accesses.mfa',['fieldId'=>'resend-'.$invite->id])
                            <button class="button secondary" aria-label="Reenviar convite para {{ $invite->name }}">Reenviar convite</button>
                        </form>
                        @endif
                        @if($invite->status!=='revoked')
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
        {{ $invitations->links() }}
    @endif
</section>
@endsection
