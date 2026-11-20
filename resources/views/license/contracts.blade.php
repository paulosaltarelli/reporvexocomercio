@if($contracts->isEmpty())
    <p class="muted">Nenhum contrato cadastrado para esta empresa.</p>
@else
    <div class="{{ $central ? 'table-scroll' : 'scroll' }}" role="region" aria-label="Contratos de licença" tabindex="0">
        <table><thead><tr><th scope="col">Contrato</th><th scope="col">Vigência</th><th scope="col">Mensalidade</th><th scope="col">Dia do vencimento</th><th scope="col">Implantação sem cobrança</th><th scope="col">Tolerância após vencimento</th>@if($central)<th scope="col">Ação</th>@endif</tr></thead>
        <tbody>@foreach($contracts as $contract)
            <tr><td>#{{ $contract->id }}</td><td>{{ $contract->start_date->format('d/m/Y') }} a {{ $contract->end_date->format('d/m/Y') }}</td>
                <td>R$ {{ number_format((float)$contract->monthly_amount,2,',','.') }}</td><td>{{ $contract->due_day }}</td>
                <td>{{ $contract->implantation_grace_days }} {{ $contract->implantation_grace_days === 1 ? 'dia' : 'dias' }}
                    @if($contract->implantation_grace_days > 0)
                        <br><small>{{ $contract->billingStartDate()->gt($contract->end_date) ? 'A implantação cobre toda a vigência.' : 'Início da cobrança a partir de '.$contract->billingStartDate()->format('d/m/Y') }}</small>
                    @endif
                </td>
                <td>{{ $contract->overdue_grace_days ?? 0 }} {{ $contract->overdue_grace_days === 1 ? 'dia' : 'dias' }}</td>
                @if($central)<td><a href="{{ route('platform.license.edit',['id'=>$company->id,'contract'=>$contract->id]) }}" aria-label="Editar contrato {{ $contract->id }}">Editar</a></td>@endif
            </tr>
        @endforeach</tbody></table>
    </div>
    <div class="pagination">{{ $contracts->links() }}</div>
@endif
