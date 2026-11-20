@extends('setup.installer-layout')
@section('title','Resumo da implantação')
@section('subtitle','Situação da implantação da sua loja.')
@section('content')
<section class="panel">
    <h2>{{ $tenant->name }}</h2>
    <dl>
        <dt>Situação da implantação</dt><dd>Concluída</dd>
        @if($companySetup?->completed_at)
            <dt>Concluída em</dt><dd>{{ $companySetup->completed_at->format('d/m/Y H:i') }}</dd>
        @endif
        <dt>Perfil de acesso</dt><dd>Implantação</dd>
    </dl>
    <p>Você pode voltar a este resumo enquanto a Central mantiver seu acesso habilitado.</p>
</section>
@endsection
