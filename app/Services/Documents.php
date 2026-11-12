<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Documents
{
    public function create(array $data, int $userId): Document
    {
        return DB::connection('tenant')->transaction(function () use ($data, $userId) {
            if ($existing = Document::where('request_key', $data['request_key'])->first()) {
                return $existing;
            }
            $items = [];
            $total = '0';
            foreach ($data['items'] as $line) {
                $model = ($line['kind'] === 'product' ? Product::query() : Service::query())->where('active', true)->findOrFail($line['id']);
                $amount = Decimal::round(bcmul((string) $line['quantity'], (string) $line['unit_price'], 8));
                $total = bcadd($total, $amount, 2);
                $items[] = ['kind' => $line['kind'], 'id' => $model->id, 'description' => $model->name, 'quantity' => (string) $line['quantity'], 'unit_price' => (string) $line['unit_price'], 'total' => $amount];
            }

            return Document::create(['type' => $data['type'], 'request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'items' => $items, 'total' => $total, 'valid_until' => $data['valid_until'] ?? null, 'asset' => $data['asset'] ?? null, 'technician' => $data['technician'] ?? null, 'notes' => $data['notes'] ?? null]);
        }, 3);
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
            $sale = app(Checkout::class)->complete(array_merge($payment, ['request_key' => $d->request_key, 'customer_id' => $d->customer_id, 'items' => $d->items, 'notes' => 'Origem: documento #'.$d->id]), $userId);
            $d->update(['sale_id' => $sale->id, 'status' => 'invoiced']);

            return $sale;
        }, 3);
    }
}
