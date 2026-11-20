<nav class="settings-navigation" aria-label="Seções de configurações">
    <a href="{{ route('platform.settings') }}" @if(request()->routeIs('platform.settings')) aria-current="page" @endif>Envio de e-mail</a>
    <a href="{{ route('platform.settings.emails.index') }}" @if(request()->routeIs('platform.settings.emails.*')) aria-current="{{ request()->routeIs('platform.settings.emails.index') ? 'page' : 'location' }}" @endif>E-mails enviados</a>
    <a href="{{ route('platform.admins') }}" @if(request()->routeIs('platform.admins*')) aria-current="page" @endif>Administradores</a>
    <a href="{{ route('platform.settings.banks.index') }}" @if(request()->routeIs('platform.settings.banks.*')) aria-current="page" @endif>Bancos</a>
</nav>
