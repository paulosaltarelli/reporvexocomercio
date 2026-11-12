<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

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
                foreach ($data['items'] as $line) {
                    if (! in_array($line['kind'], ['product', 'service'], true)) {
                        throw ValidationException::withMessages(['items' => 'Tipo de item inválido.']);
                    }
                    $model = ($line['kind'] === 'product' ? Product::query() : Service::query())->where('active', true)->findOrFail($line['id']);
                    $quantity = Decimal::input((string) $line['quantity'], 3, 'items', '0.001', '999999');
                    $price = Decimal::input((string) $line['unit_price'], 2, 'items', '0', '999999999.99');
                    $amount = Decimal::input(Decimal::round(bcmul($quantity, $price, 8)), 2, 'items');
                    $total = bcadd($total, $amount, 2);
                    $items[] = ['kind' => $line['kind'], 'id' => $model->id, 'description' => $model->name, 'quantity' => $quantity, 'unit_price' => $price, 'total' => $amount];
                }

                Decimal::input($total, 2, 'items');

                return Document::create(['type' => $data['type'], 'request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'items' => $items, 'total' => $total, 'valid_until' => $data['valid_until'] ?? null, 'asset' => $data['asset'] ?? null, 'technician' => $data['technician'] ?? null, 'notes' => $data['notes'] ?? null]);
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
            $sale = app(Checkout::class)->complete(array_merge($payment, ['request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:document-sale:'.$d->request_key)->toString(), 'customer_id' => $d->customer_id, 'items' => $d->items, 'notes' => 'Origem: documento #'.$d->id]), $userId);
            $d->update(['sale_id' => $sale->id, 'status' => 'invoiced']);

            return $sale;
        }, 3);
    }
}
