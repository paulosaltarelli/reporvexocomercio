@if(\Illuminate\Support\Facades\Route::has('restaurant.activity'))
<section class="operation-live-status" data-operation-activity data-operation-activity-url="{{ route('restaurant.activity',['company'=>$tenant->slug,'view'=>$activityScope??'index','order_id'=>$activityOrderId??null,'station'=>request('station')]) }}" data-operation-revision="{{ $activityState['version']??$activityRevision??$activityVersion??'' }}" aria-label="Atualização do atendimento">
    <span data-operation-connection>Atualização manual</span>
    <span class="hint" data-activity-message role="status">Os pedidos continuam disponíveis nesta tela.</span>
    <button class="button secondary" type="button" data-activity-reload hidden>Atualizar pedidos</button>
    <a href="{{ route('login',['company'=>$tenant->slug]) }}" data-activity-login hidden>Entrar novamente</a>
</section>
@once
@push('scripts')<script src="/operation-activity.js?v={{ filemtime(public_path('operation-activity.js')) }}" defer></script>@endpush
@endonce
@endif
