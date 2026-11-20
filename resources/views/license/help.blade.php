@extends('layout')
@section('title','Ajuda com a licença')
@section('content')
<section class="panel">
    <h2>Como regularizar o acesso</h2>
    @if($currentUser->role==='admin')
        <p>Abra o financeiro da licença para conferir quais parcelas venceram, consultar os pagamentos e verificar o saldo em aberto.</p>
        <a class="button" href="{{ route('license.index',['company'=>$tenant->slug]) }}">Consultar financeiro da licença</a>
    @else
        <p>Peça ao administrador desta empresa que consulte o financeiro da licença. Ele pode verificar quais parcelas venceram e acompanhar a confirmação do pagamento.</p>
    @endif
    <p>Quando a quitação das parcelas vencidas for confirmada, abra o recurso novamente. Você pode continuar usando as funções liberadas pelo seu contrato e pelo seu perfil.</p>
</section>
@endsection
