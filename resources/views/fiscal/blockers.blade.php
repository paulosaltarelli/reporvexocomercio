<div class="notice" role="status"><strong>Transmissão bloqueada</strong><ul>
@foreach($report['blockers'] as $issue)<li>{{ $issue['message'] }}</li>@endforeach
</ul></div>
