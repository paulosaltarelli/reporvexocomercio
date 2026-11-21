@php
    $profile = $companyProfile ?? null;
    $name = $profile?->trade_name ?: ($profile?->legal_name ?: $tenant->name);
    $address = implode(', ', array_filter([$profile?->street, $profile?->number, $profile?->district]));
@endphp
<header class="receipt-company">
    @if($profile?->logo_path)<img class="receipt-logo" src="{{ route('business.logo',['company'=>$tenant->slug,'v'=>$profile->logoVersion()]) }}" alt="{{ $name }}">@endif
    <h1>{{ $name }}</h1>
    @if($profile?->document)<p>Documento {{ $profile->document }}</p>@endif
    @if($address)<p>{{ $address }}</p>@endif
    @if($profile?->city || $profile?->state)<p>{{ implode(' / ', array_filter([$profile?->city,$profile?->state])) }}</p>@endif
    @if($profile?->phone)<p>Tel. {{ $profile->phone }}</p>@endif
</header>
