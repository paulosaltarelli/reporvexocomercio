<div class="notice" role="status"><strong>Conferências e pendências</strong><ul>
@foreach($report['blockers'] as $issue)<li>{{ $issue['message'] }}</li>@endforeach
</ul></div>
