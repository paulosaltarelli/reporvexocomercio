<section class="panel">
    <h2>Responsável pelo caixa e pelas vendas</h2>
    <p>Cadastre um responsável para a empresa usar nos atendimentos. Telefone e e-mail são opcionais.</p>
    @if($sellers->isNotEmpty())
        <p>Responsáveis já cadastrados: {{ $sellers->pluck('name')->implode(', ') }}.</p>
        <p><a href="{{ route('setup',['company'=>$tenant->slug,'step'=>5]) }}">Continuar com a equipe cadastrada</a></p>
    @endif
    <form method="post" action="{{ route('setup.team.save',['company'=>$tenant->slug]) }}">
        @csrf<input type="hidden" name="active" value="1">
        <div class="form-grid">
            <div class="field"><label for="installer-team-name">Nome do responsável</label><input id="installer-team-name" name="name" maxlength="180" value="{{ old('name') }}" required></div>
            <div class="field"><label for="installer-team-phone">Telefone (opcional)</label><input id="installer-team-phone" type="tel" name="phone" maxlength="30" value="{{ old('phone') }}"></div>
            <div class="field"><label for="installer-team-email">E-mail de contato (opcional)</label><input id="installer-team-email" type="email" name="email" maxlength="180" value="{{ old('email') }}"></div>
        </div>
        <div class="setup-actions">
            <a class="button secondary" href="{{ route('setup',['company'=>$tenant->slug,'step'=>$nfceAvailable?3:2]) }}">Voltar</a>
            <button class="primary">Salvar responsável e continuar</button>
        </div>
    </form>
</section>
