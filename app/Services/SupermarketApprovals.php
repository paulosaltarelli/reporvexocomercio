<?php

namespace App\Services;

use App\Models\OperationRecord;
use App\Models\Product;
use App\Models\SupermarketApproval;
use App\Models\SupermarketCart;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** A grant authorizes exactly one persisted operation; it never changes a user's permissions. */
final class SupermarketApprovals
{
    public function __construct(private Supermarket $market) {}

    public function request(int $cartId, array $input, int $userId): SupermarketApproval
    {
        $input['reason'] = trim((string) ($input['reason'] ?? ''));
        $data = Validator::make($input, ['request_key' => 'required|uuid', 'version' => 'required|integer|min:0',
            'action' => 'required|in:remove,reduce,discount,cancel', 'line_key' => 'nullable|uuid',
            'quantity' => 'nullable|string|max:24', 'discount_percent' => 'nullable|string|max:12',
            'reason' => 'required|string|min:3|max:1000'])->validate();
        $payload = $this->payload($data);
        $hash = hash('sha256', json_encode([$cartId, $userId, (int) $data['version'], $data['action'], $payload, $data['reason']], JSON_THROW_ON_ERROR));
        return DB::connection('tenant')->transaction(function () use ($cartId, $data, $payload, $hash, $userId) {
            $this->market->actor($userId);
            $cart = $this->market->owned($cartId, $userId, true);
            $existing = SupermarketApproval::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if (!hash_equals($existing->payload_hash, $hash)) $this->invalid('A identificação já pertence a outra solicitação.');
                return $existing;
            }
            $this->cartState($cart, (int) $data['version'], $data['action']);
            $this->changedItems($cart, $data['action'], $payload);
            if ($data['action'] !== 'cancel') {
                $index = array_search($payload['line_key'], array_column($cart->items, 'line_key'), true);
                $payload['item_snapshot'] = $cart->items[$index];
            } else {
                $payload['cart_total'] = $cart->total;
                $payload['cart_items_count'] = count($cart->items);
            }
            $approval = SupermarketApproval::create(['request_key' => $data['request_key'], 'cart_id' => $cart->id,
                'requested_by' => $userId, 'cart_version' => $cart->version, 'action' => $data['action'],
                'payload' => $payload, 'payload_hash' => $hash, 'reason' => $data['reason'], 'expires_at' => now()->addMinutes(15)]);
            $this->audit('market_approval_request', $approval, $userId);
            return $approval;
        }, 3);
    }

    private function payload(array $data): array
    {
        if ($data['action'] === 'cancel') return [];
        if (empty($data['line_key'])) $this->invalid('Selecione o item da solicitação.');
        $payload = ['line_key' => $data['line_key']];
        if ($data['action'] === 'reduce') $payload['quantity'] = Decimal::input((string) ($data['quantity'] ?? ''), 3, 'quantity', '0.001', '999999');
        if ($data['action'] === 'discount') $payload['discount_percent'] = Decimal::input((string) ($data['discount_percent'] ?? ''), 2, 'discount_percent', '0.01', '100');
        return $payload;
    }

    private function cartState(SupermarketCart $cart, int $version, string $action): void
    {
        $statuses = $action === 'cancel' ? ['active', 'suspended'] : ['active'];
        if ($cart->version !== $version || !in_array($cart->status, $statuses, true)) {
            $this->invalid('O atendimento mudou. Confira os itens e envie uma nova solicitação.');
        }
    }

    /** Validated again at approval and consumption, including the current product discount limit. */
    private function changedItems(SupermarketCart $cart, string $action, array $payload): array
    {
        $items = $cart->items;
        if ($action === 'cancel') return $items;
        $index = array_search($payload['line_key'], array_column($items, 'line_key'), true);
        if ($index === false) $this->invalid('O item solicitado não existe mais neste atendimento.');
        if ($action === 'remove') {
            unset($items[$index]);
            return array_values($items);
        }
        $line = $items[$index];
        $product = Product::where('active', true)->where('type', 'product')->whereKey($line['product_id'])->lockForUpdate()->first();
        if (!$product || $product->unit !== $line['unit'] || bccomp((string) $product->sale_price, $line['unit_price'], 2) !== 0) {
            $this->invalid('O cadastro ou o preço do item mudou. Confira o atendimento antes de solicitar autorização.');
        }
        if ($action === 'reduce') {
            if (!empty($line['weighted_label'])) $this->invalid('A embalagem pesável precisa ser retirada e lida novamente.');
            if (bccomp($payload['quantity'], $line['quantity'], 3) >= 0) $this->invalid('Informe uma quantidade menor que a atual.');
            $line['quantity'] = $payload['quantity'];
        } else {
            $line['discount_percent'] = $payload['discount_percent'];
        }
        $terms = app(CommercialRules::class)->quote($product, ['quantity' => $line['quantity'],
            'unit_price' => $line['unit_price'], 'catalog_price_seen' => $line['unit_price'],
            'discount_percent' => $line['discount_percent'] ?? '0.00']);
        $line['total'] = $terms['total'];
        $items[$index] = $line;
        return $items;
    }

    public function decide(int $approvalId, string $decision, string $reason, int $supervisorId): SupermarketApproval
    {
        $reason = trim($reason);
        Validator::make(compact('decision', 'reason'), ['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:3|max:1000'])->validate();
        return DB::connection('tenant')->transaction(function () use ($approvalId, $decision, $reason, $supervisorId) {
            $supervisor = $this->market->actor($supervisorId, 'supermarket.supervise');
            $approval = SupermarketApproval::findOrFail($approvalId);
            $cart = SupermarketCart::whereKey($approval->cart_id)->lockForUpdate()->firstOrFail();
            $approval = SupermarketApproval::whereKey($approvalId)->lockForUpdate()->firstOrFail();
            $status = $decision === 'approve' ? 'approved' : 'rejected';
            if ($approval->status === $status && (int) $approval->decided_by === $supervisorId && $approval->decision_reason === $reason) return $approval;
            if ($approval->status !== 'pending' || $approval->expires_at->isPast()) $this->invalid('Esta solicitação já foi decidida ou expirou.');
            if ($decision === 'approve') {
                $this->cartState($cart, $approval->cart_version, $approval->action);
                $this->discountLimit($supervisor, $approval);
                $this->changedItems($cart, $approval->action, $approval->payload);
            }
            $approval->update(['status' => $status, 'decided_by' => $supervisorId, 'decided_at' => now(), 'decision_reason' => $reason]);
            $this->audit('market_approval_decide', $approval, $supervisorId);
            return $approval;
        }, 3);
    }

    public function apply(int $cartId, int $approvalId, int $version, int $userId): SupermarketCart
    {
        return DB::connection('tenant')->transaction(function () use ($cartId, $approvalId, $version, $userId) {
            $this->market->actor($userId);
            $cart = $this->market->owned($cartId, $userId, true);
            $approval = SupermarketApproval::whereKey($approvalId)->where('cart_id', $cartId)->where('requested_by', $userId)->lockForUpdate()->firstOrFail();
            if ($approval->status === 'used') return $cart;
            if ($approval->status !== 'approved' || $approval->expires_at->isPast()) $this->invalid('A autorização não está aprovada ou expirou.');
            $supervisor = User::whereKey($approval->decided_by)->where('active', true)->first();
            if ($supervisor && $supervisor->getRawOriginal('role') === User::ROLE_INSTALLER) InstallerAccess::applyGrant($supervisor);
            if (!$supervisor || !$supervisor->allows('supermarket.supervise')) $this->invalid('O responsável pela autorização não possui mais acesso de supervisão.');
            $this->discountLimit($supervisor, $approval);
            if ($version !== $approval->cart_version) $this->invalid('A versão informada não corresponde à autorização.');
            $this->cartState($cart, $version, $approval->action);
            $before = ['items' => $cart->items, 'status' => $cart->status, 'version' => $cart->version, 'total' => $cart->total];
            $items = $this->changedItems($cart, $approval->action, $approval->payload);
            if ($approval->action === 'discount') {
                foreach ($items as &$item) {
                    if ($item['line_key'] === $approval->payload['line_key']) $item['supervisor_approval_id'] = $approval->id;
                }
                unset($item);
            }
            $changes = ['version' => $cart->version + 1];
            if ($approval->action === 'cancel') $changes += ['status' => 'cancelled', 'cancellation_reason' => $approval->reason];
            else $changes += ['items' => $items, 'total' => array_reduce($items, fn ($sum, $line) => bcadd($sum, $line['total'], 2), '0.00')];
            $cart->update($changes);
            $approval->update(['status' => 'used', 'applied_at' => now()]);
            $this->audit('market_approval_apply', $approval, $userId, $before);
            return $cart;
        }, 3);
    }

    private function discountLimit(User $supervisor, SupermarketApproval $approval): void
    {
        if ($approval->action === 'discount' && bccomp($approval->payload['discount_percent'],
            app(\App\Access\AccessProfiles::class)->discountLimit($supervisor), 2) > 0) {
            $this->invalid('O desconto solicitado ultrapassa o limite do perfil deste supervisor.');
        }
    }

    private function audit(string $action, SupermarketApproval $approval, int $actor, array $before = []): void
    {
        OperationRecord::create(['action' => $action, 'version' => 1, 'user_id' => $actor, 'before' => $before,
            'after' => ['approval_id' => $approval->id, 'cart_id' => $approval->cart_id, 'requested_by' => $approval->requested_by,
                'decided_by' => $approval->decided_by, 'action' => $approval->action, 'payload' => $approval->payload,
                'status' => $approval->status, 'reason' => $approval->reason, 'decision_reason' => $approval->decision_reason], 'created_at' => now()]);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['approval' => $message]);
    }
}
