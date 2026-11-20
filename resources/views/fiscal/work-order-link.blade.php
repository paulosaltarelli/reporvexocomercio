@if($split = $document->snapshot['work_order_split'] ?? null)
<p>OS #{{ $split['work_order_id'] }} · Mercadorias R$ {{ number_format($split['goods_total'],2,',','.') }} · Serviço R$ {{ number_format($split['services_total'],2,',','.') }} · Total da venda R$ {{ number_format($split['sale_total'],2,',','.') }}.</p>
<div class="actions">
<a href="{{ route(config('fiscal.product_invoices_enabled',false) ? 'fiscal.sale' : 'nfse.sale',['company'=>$tenant->slug,'id'=>$split['sale_id']]) }}">Conferir dados fiscais da venda</a>
@isset($split['nfse_document_id'])<a href="{{ route('nfse.show',['company'=>$tenant->slug,'id'=>$split['nfse_document_id']]) }}">Abrir DPS vinculada</a>@endisset
</div>
@endif
