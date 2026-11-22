@php
    $isPublished = ($companySetup?->configuration['public_catalog_published'] ?? false) === true;
    $catalogAvailable = ($operationConfig['catalog_mode'] ?? 'mixed') !== 'services' && !isset(request()->attributes->get('restrictedModules', [])['catalog']);
    $catalogUrl = \App\Tenancy\CompanyHosts::url($tenant->slug, 'storefront.index');
@endphp
<section class="panel" id="catalogo-publico" aria-labelledby="catalog-title">
    <h2 id="catalog-title">Catálogo público</h2>
    @if(!$catalogAvailable)
        <p>O catálogo está indisponível. Confira o modo de operação da empresa e o acesso ao módulo de cadastros comerciais.</p>
    @elseif($isPublished)
        <p><strong>Publicado.</strong> Os preços e produtos ativos acompanham o cadastro da loja.</p>
        <div class="field">
            <label for="catalog-url">Link do catálogo</label>
            <input id="catalog-url" value="{{ $catalogUrl }}" readonly>
        </div>
        <div class="actions">
            <button type="button" class="button secondary" data-copy-catalog hidden>Copiar link</button>
            <a class="button secondary" href="{{ $catalogUrl }}" target="_blank" rel="noopener">Abrir catálogo</a>
            <span role="status" id="catalog-copy-status"></span>
        </div>
    @else
        <p>A publicação disponibiliza o catálogo da loja a qualquer pessoa com o link.</p>
    @endif
    @if($catalogAvailable || $isPublished)
    <form method="post" action="{{ route('business.public-catalog', ['company' => $tenant->slug]) }}">
        @csrf
        <input type="hidden" name="version" value="{{ $companySetup?->version ?? 0 }}">
        <input type="hidden" name="visibility" value="{{ $isPublished ? 'private' : 'public' }}">
        @unless($isPublished)
            <label class="checkbox-line"><input type="checkbox" name="publish_all" value="1" required>
                Autorizo divulgar o nome e a marca da loja, além do nome, foto, unidade e preço de venda de todos os produtos ativos, inclusive os que forem cadastrados ou reativados depois.</label>
        @endunless
        <div class="actions"><button class="button {{ $isPublished ? 'secondary' : 'primary' }}">{{ $isPublished ? 'Retirar do ar' : 'Publicar catálogo' }}</button></div>
    </form>
    @endif
</section>
@push('scripts')
<script src="/storefront-publication.js" defer></script>
@endpush
