@extends('layout')
@section('title','Acesso limitado pela licença')
@section('content')
<div class="panel">
    <p>{{ $message }}</p>
    <a href="{{ route('license.help',['company'=>$tenant->slug]) }}">Como regularizar o acesso</a>
    @if($currentUser->role==='admin')
        <a class="button" href="{{ route('license.index',['company'=>$tenant->slug]) }}">Consultar financeiro da licença</a>
    @endif
</div>
@endsection
