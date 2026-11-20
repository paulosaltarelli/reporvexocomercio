@if($installments->isEmpty())
    <p class="muted">Nenhuma parcela de licença para exibir.</p>
@else
    <div class="{{ $central ? 'table-scroll' : 'scroll' }}" role="region" aria-label="Parcelas de licença" tabindex="0">
        <table><thead><tr><th scope="col">Competência</th><th scope="col">Contrato</th><th scope="col">Vencimento</th><th scope="col">Valor</th><th scope="col">Pago</th><th scope="col">Saldo</th><th scope="col">Situação</th><th scope="col">Parcela</th></tr></thead>
        <tbody>@foreach($installments as $row)
            <tr><td>{{ $row->period->format('m/Y') }}</td><td>#{{ $row->contract_id }}</td><td>{{ $row->due_date->format('d/m/Y') }}</td>
                <td>R$ {{ number_format((float)$row->amount,2,',','.') }}</td><td>R$ {{ number_format((float)$row->paid_amount,2,',','.') }}</td><td>R$ {{ number_format((float)$row->balance,2,',','.') }}</td>
                <td>@include('license.installment-status', ['status'=>$row->status])</td>
                <td><a href="{{ $central
                    ? route('platform.license.installments.show', ['id' => $row->tenant_id, 'installment' => $row->id])
                    : route('license.installments.show', ['company' => $tenant->slug, 'installment' => $row->id]) }}"
                    aria-label="Ver parcela de {{ $row->period->format('m/Y') }}">Ver parcela</a></td>
            </tr>
        @endforeach</tbody></table>
    </div>
    <div class="pagination">{{ $installments->links() }}</div>
@endif
