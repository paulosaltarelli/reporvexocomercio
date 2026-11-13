<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Documents
{
    public function create(array $data, int $userId): Document
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId) {
                if ($existing = Document::where('request_key', $data['request_key'])->first()) {
                    return $existing;
                }
                $data['items'] ??= [];
                if (! in_array($data['type'], ['quote', 'work_order'], true) || ! is_array($data['items']) || count($data['items']) > 50 || ($data['type'] === 'quote' && $data['items'] === [])) {
                    throw ValidationException::withMessages(['items' => 'Orçamentos exigem de 1 a 50 itens. A recepção da OS pode ser aberta sem itens.']);
                }
                $rules = app(CommercialRules::class);
                $seller = $rules->seller($userId, isset($data['seller_id']) ? (int) $data['seller_id'] : null,
                    isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null);
                [$items, $total, $subtotal, $discountTotal] = $this->terms($data['items']);

                $document = Document::create(['salesperson_id' => $seller->id, 'seller_name' => $seller->name, 'commercial_version' => 1, 'subtotal' => $subtotal, 'discount_total' => $discountTotal, 'type' => $data['type'], 'request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'items' => $items, 'total' => $total, 'valid_until' => $data['valid_until'] ?? null, 'asset' => $data['asset'] ?? null, 'technician' => $data['technician'] ?? null, 'notes' => $data['notes'] ?? null]);
                app(SellerAssignment::class)->recordInitial($document, $seller, $userId);

                return $document;
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Document::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    /** Optimistic guard protects diagnosis, commercial terms and credited ownership together. */
    public function editHash(Document $document): string
    {
        return hash('sha256', json_encode($document->only(['id', 'type', 'status', 'sale_id', 'salesperson_id', 'seller_name', 'customer_id', 'valid_until', 'asset', 'technician', 'notes', 'items', 'total', 'subtotal', 'discount_total', 'commercial_version', 'updated_at']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function updateDraft(int $id, array $data, int $userId): Document
    {
        abort_unless(User::whereKey($userId)->where('active', true)->exists(), 403);
        $validated = Validator::make($data, [
            'expected_hash' => 'required|regex:/^[a-f0-9]{64}$/D',
            'customer_id' => 'nullable|integer|exists:tenant.customers,id', 'valid_until' => 'nullable|date',
            'asset' => 'nullable|string|max:255', 'technician' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
            'seller_id' => 'prohibited', 'salesperson_id' => 'prohibited', 'type' => 'prohibited',
            'items' => 'sometimes|array|max:50', 'items.*.kind' => 'required|in:product,service',
            'items.*.id' => 'required|integer|min:1', 'items.*.quantity' => 'required|numeric|min:0.001|max:999999|decimal:0,3',
            'items.*.unit_price' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2',
            'items.*.discount_percent' => 'nullable|numeric|min:0|max:100|decimal:0,2',
            'items.*.catalog_price_seen' => 'nullable|numeric|min:0|max:999999999999.99|decimal:0,2',
        ])->validate();
        return DB::connection('tenant')->transaction(function () use ($id, $validated) {
            $document = Document::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($document->type !== 'work_order' || $document->status !== 'draft' || $document->sale_id) {
                throw ValidationException::withMessages(['document' => 'Somente uma OS aberta, ainda sem aprovação ou venda, pode ser editada.']);
            }
            if (! hash_equals($this->editHash($document), $validated['expected_hash'])) {
                throw ValidationException::withMessages(['expected_hash' => 'A OS foi alterada por outra operação. Reabra e revise antes de salvar.']);
            }
            [$items, $total, $subtotal, $discountTotal] = $this->terms($validated['items'] ?? []);
            $document->update([
                'customer_id' => $validated['customer_id'] ?? null, 'valid_until' => $validated['valid_until'] ?? null,
                'asset' => $validated['asset'] ?? null, 'technician' => $validated['technician'] ?? null, 'notes' => $validated['notes'] ?? null,
                'items' => $items, 'total' => $total, 'subtotal' => $subtotal, 'discount_total' => $discountTotal, 'commercial_version' => 1,
            ]);
            return $document;
        }, 3);
    }

    private function terms(array $lines): array
    {
        $items = [];
        $total = $subtotal = $discountTotal = '0.00';
        $rules = app(CommercialRules::class);
        foreach ($lines as $line) {
            if (! in_array($line['kind'], ['product', 'service'], true)) { throw ValidationException::withMessages(['items' => 'Tipo de item inválido.']); }
            $model = $line['kind'] === 'product' ? Product::where('active', true)->sharedLock()->findOrFail($line['id']) : Product::resolveLegacyService((int) $line['id'], true);
            $terms = $rules->quote($model, $line);
            $total = bcadd($total, $terms['total'], 2);
            $subtotal = bcadd($subtotal, $terms['gross_total'], 2);
            $discountTotal = bcadd($discountTotal, $terms['discount_amount'], 2);
            $items[] = array_merge(['kind' => $line['kind'], 'id' => $line['kind'] === 'product' ? $model->id : (int) $line['id'], 'description' => $model->name], $terms);
        }
        Decimal::input($total, 2, 'items');
        Decimal::input($subtotal, 2, 'items');
        Decimal::input($discountTotal, 2, 'items');
        return [$items, $total, $subtotal, $discountTotal];
    }

    public function transition(int $id, string $status): void
    {
        DB::connection('tenant')->transaction(function () use ($id, $status) {
            $d = Document::lockForUpdate()->findOrFail($id);
            $allowed = match ($d->status) {
                'draft' => ['approved', 'cancelled'],'approved' => $d->type === 'work_order' ? ['in_progress', 'cancelled'] : ['cancelled'],'in_progress' => ['completed', 'cancelled'],'completed' => ['cancelled'],default => []
            };
            if (! in_array($status, $allowed, true)) {
                throw ValidationException::withMessages(['status' => 'Transição não permitida para este documento.']);
            }
            if ($status !== 'cancelled' && empty($d->items)) {
                throw ValidationException::withMessages(['items' => 'Inclua os produtos ou serviços e revise o diagnóstico antes de aprovar a OS.']);
            }
            $d->update(['status' => $status]);
        }, 3);
    }

    public function invoice(int $id, array $payment, int $userId): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($id, $payment, $userId) {
            $d = Document::lockForUpdate()->findOrFail($id);
            if ($d->sale_id) {
                return Sale::findOrFail($d->sale_id);
            }
            if (($d->type === 'quote' && $d->status !== 'approved') || ($d->type === 'work_order' && $d->status !== 'completed')) {
                throw ValidationException::withMessages(['status' => 'Aprove o orçamento ou conclua a ordem de serviço antes de gerar a venda.']);
            }
            if (empty($d->items)) {
                throw ValidationException::withMessages(['items' => 'Documento sem itens não pode gerar uma venda.']);
            }
            $sale = app(Checkout::class)->completeFromDocument($d->id, $payment, $userId);

            return $sale;
        }, 3);
    }
}
