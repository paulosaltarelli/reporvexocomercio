<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
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
                if (! in_array($data['type'], ['quote', 'work_order'], true) || $data['items'] === [] || count($data['items']) > 50) {
                    throw ValidationException::withMessages(['items' => 'Informe um documento válido com 1 a 50 itens.']);
                }
                $items = [];
                $total = '0';
                $subtotal = '0';
                $discountTotal = '0';
                $rules = app(CommercialRules::class);
                $seller = $rules->seller($userId, isset($data['seller_id']) ? (int) $data['seller_id'] : null,
                    isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null);
                foreach ($data['items'] as $line) {
                    if (! in_array($line['kind'], ['product', 'service'], true)) {
                        throw ValidationException::withMessages(['items' => 'Tipo de item inválido.']);
                    }
                    $model = $line['kind'] === 'product' ? Product::where('active', true)->sharedLock()->findOrFail($line['id'])
                        : Product::resolveLegacyService((int) $line['id'], true);
                    $terms = $rules->quote($model, $line);
                    $total = bcadd($total, $terms['total'], 2);
                    $subtotal = bcadd($subtotal, $terms['gross_total'], 2);
                    $discountTotal = bcadd($discountTotal, $terms['discount_amount'], 2);
                    $items[] = array_merge(['kind' => $line['kind'], 'id' => $line['kind'] === 'product' ? $model->id : (int) $line['id'], 'description' => $model->name], $terms);
                }

                Decimal::input($total, 2, 'items');
                Decimal::input($subtotal, 2, 'items');
                Decimal::input($discountTotal, 2, 'items');

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
            $sale = app(Checkout::class)->completeFromDocument($d->id, $payment, $userId);

            return $sale;
        }, 3);
    }
}
