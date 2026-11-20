<nav class="actions" aria-label="Seções de configurações">
    <a class="button {{ request()->routeIs('platform.settings') ? '' : 'secondary' }}" href="{{ route('platform.settings') }}" @if(request()->routeIs('platform.settings')) aria-current="page" @endif>E-mail</a>
    <a class="button {{ request()->routeIs('platform.settings.emails.*') ? '' : 'secondary' }}" href="{{ route('platform.settings.emails.index') }}" @if(request()->routeIs('platform.settings.emails.*')) aria-current="page" @endif>E-mails enviados</a>
    <a class="button {{ request()->routeIs('platform.admins*') ? '' : 'secondary' }}" href="{{ route('platform.admins') }}" @if(request()->routeIs('platform.admins*')) aria-current="page" @endif>Administradores</a>
    <a class="button {{ request()->routeIs('platform.settings.banks.*') ? '' : 'secondary' }}" href="{{ route('platform.settings.banks.index') }}" @if(request()->routeIs('platform.settings.banks.*')) aria-current="page" @endif>Bancos</a>
</nav>
