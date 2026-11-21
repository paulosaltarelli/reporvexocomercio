@extends('printing.thermal')
@section('title','Teste de impressão')
@section('content')
<header class="block"><h1>{{ $companyProfile?->trade_name ?: $companyProfile?->legal_name ?: $tenant->name }}</h1><p>TESTE DE IMPRESSÃO</p><p>Documento não fiscal</p><p>{{ now()->format('d/m/Y H:i') }}</p></header>
<section class="block"><p>Texto normal · acentuação: ação, café, açúcar.</p><p><strong>Texto em destaque para conferência.</strong></p><div class="row"><span>Produto de teste 1</span><strong>R$ 12,34</strong></div><div class="row"><span>Produto de teste 2</span><strong>R$ 5,66</strong></div></section>
<section class="block"><div class="row total"><strong>Total de teste</strong><strong>R$ 18,00</strong></div><p>Confira se os valores aparecem inteiros e alinhados.</p></section>
<footer class="block"><p>Vendra por Vexo</p><p>Este teste não registra venda nem movimenta estoque ou caixa.</p></footer>
@endsection
