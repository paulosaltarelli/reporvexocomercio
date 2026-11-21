@if(\Illuminate\Support\Facades\Route::has('supermarket.approvals.request'))
<details class="operation-line-actions">
    <summary>Solicitar autorização ao supervisor</summary>
    <form method="post" action="{{ route('supermarket.approvals.request',['company'=>$tenant->slug,'cart'=>$cart->id]) }}" class="operation-approval-request">
        @csrf
        <input type="hidden" name="request_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        <input type="hidden" name="version" value="{{ $cart->version }}">
        @if(isset($approvalLine))<input type="hidden" name="line_key" value="{{ $approvalLine['line_key'] }}">@endif
        <div class="field"><label for="approval-action-{{ $approvalKey }}">Alteração solicitada</label><select id="approval-action-{{ $approvalKey }}" name="action" data-approval-action>
            @if(isset($approvalLine))<option value="remove">Retirar este item</option>@if(empty($approvalLine['weighted_label']))<option value="reduce">Reduzir a quantidade</option>@endif<option value="discount">Desconto neste item</option>
            @else<option value="cancel">Cancelar a venda</option>@endif
        </select></div>
        @if(isset($approvalLine) && empty($approvalLine['weighted_label']))<div class="field" data-approval-field="reduce"><label for="approval-quantity-{{ $approvalKey }}">Nova quantidade</label><input id="approval-quantity-{{ $approvalKey }}" name="quantity" type="number" min="0.001" max="{{ $approvalLine['quantity'] }}" step="0.001" inputmode="decimal"></div>@endif
        @if(isset($approvalLine))<div class="field" data-approval-field="discount"><label for="approval-discount-{{ $approvalKey }}">Desconto solicitado (%)</label><input id="approval-discount-{{ $approvalKey }}" name="discount_percent" type="number" min="0.01" max="100" step="0.01" inputmode="decimal"></div>@endif
        <div class="field"><label for="approval-note-{{ $approvalKey }}">Motivo</label><input id="approval-note-{{ $approvalKey }}" name="reason" minlength="3" maxlength="1000" required></div>
        <button class="button secondary">Enviar solicitação</button>
        <p class="hint">O supervisor decide no próprio acesso. A alteração só é aplicada após a autorização e a conferência da venda.</p>
    </form>
</details>
@endif
