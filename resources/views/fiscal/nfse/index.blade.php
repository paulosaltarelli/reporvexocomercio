@extends('layout')
@section('title','Arquivo de serviços')
@section('subtitle','Consulte os registros fiscais de serviço arquivados pela empresa.')
@section('content')
<section class="panel">
    @if($documents->isEmpty())
        <p>Nenhum registro arquivado.</p>
    @else
        <div class="scroll"><table>
            <thead><tr><th>Registro</th><th>Venda</th><th>Valor</th><th></th></tr></thead>
            <tbody>@foreach($documents as $document)
                <tr><td>DPS {{ $document->number }} · série {{ $document->series }}</td><td>#{{ $document->sale_id }}</td><td>R$ {{ number_format($document->total,2,',','.') }}</td>
                    <td><a href="{{ route('nfse.show',['company'=>$tenant->slug,'id'=>$document->id]) }}">Consultar registro</a></td></tr>
            @endforeach</tbody>
        </table></div>
    @endif
    {{ $documents->links() }}
</section>
@endsection
