<?php

namespace App\Services;

use App\Models\Product;
use App\Models\OperationRecord;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class PurchaseService
{
    public function receive(array $data, int $userId): Purchase
    {
        if (! Str::isUuid((string) ($data['request_key'] ?? ''))) {
            throw ValidationException::withMessages(['request_key' => 'Chave da compra inválida.']);
        }
        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId) {
                $hasInvoice = PurchaseInvoice::hasInvoice($data);
                if ($hasInvoice) PurchaseInvoice::lockRegister();
                $configuration=app(CompanyOperations::class)->lock();
                app(CompanyOperations::class)->assertItem('product',$configuration);
                if ($existing = Purchase::where('request_key', $data['request_key'])->first()) {
                    OperationReplay::verify($existing, $data, $userId);
                    return $existing;
                }
                $supplier = Supplier::findOrFail($data['supplier_id']);
                $date = FinancialService::date((string) $data['purchased_on'], 'purchased_on');
                $choice=app(PaymentMethods::class)->resolve($data['payment_option']??null,(string)$data['payment_method']);
                $method = FinancialService::paymentMethod($choice['type']);
                $lines = $data['items'];
                if ($lines === [] || count($lines) > 50) {
                    throw ValidationException::withMessages(['items' => 'Informe de 1 a 50 itens.']);
                }
                $invoice = null;
                if ($hasInvoice) {
                    $invoice = PurchaseInvoice::identity($data['invoice'] ?? [],$supplier,$date->toDateString())
                        + PurchaseInvoice::calculate($lines,$data['invoice'] ?? []);
                    foreach ($lines as $index => &$line) $line['invoice_index'] = $index;
                    unset($line);
                }
                $purchase = Purchase::create([
                    'request_key' => $data['request_key'], 'supplier_id' => $data['supplier_id'], 'user_id' => $userId,
                    'reference' => $data['reference'] ?? null, 'purchased_on' => $date->toDateString(),
                    'notes' => $data['notes'] ?? null, 'total' => '0.00', 'payment_method' => $method, 'paid' => false,
                ]);
                OperationReplay::record($purchase, $data, $userId);
                usort($lines, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
                $total = '0.00';
                $referenceProducts = [];
                foreach ($lines as $line) {
                    $product = Product::where('active', true)->lockForUpdate()->findOrFail($line['product_id']);
                    if ($product->type !== 'product') {
                        throw ValidationException::withMessages(['items' => 'O recebimento de compras aceita somente produtos. Registre despesas de serviços pelo financeiro.']);
                    }
                    $quantity = Decimal::input((string) $line['quantity'], 3, 'items', '0.001', '999999999');
                    // Legacy purchases use the supplied effective unit cost.
                    $cost = Decimal::input((string) $line['unit_cost'], 4, 'items', '0', '999999999.9999');
                    $fiscalLine = $invoice['lines'][$line['invoice_index'] ?? -1] ?? null;
                    $lineTotal = $fiscalLine['payable'] ?? Decimal::input(Decimal::round(bcmul($quantity, $cost, 8)), 2, 'items');
                    [$stockQuantity, , $detail] = MeasureUnits::convert($product, $configuration, $line, 'purchase');
                    $acquisition = $fiscalLine['acquisition'] ?? bcmul($quantity,$cost,7);
                    $stockCost = Decimal::input(Decimal::round(bcdiv($acquisition, $stockQuantity, 12), 4), 4, 'items', '0', '999999999.9999');
                    if (bccomp($acquisition, '0', 7) > 0 && bccomp($stockCost, '0', 4) === 0) {
                        throw ValidationException::withMessages(['items'=>'O custo convertido é inferior à precisão do estoque.']);
                    }
                    $item = PurchaseItem::create([
                        'purchase_id' => $purchase->id, 'product_id' => $product->id, 'description' => $product->name.$detail,
                        'quantity' => $quantity, 'unit_cost' => $cost, 'total' => $lineTotal,
                    ]);
                    if ($fiscalLine !== null) $invoice['lines'][$line['invoice_index']]['item_id'] = $item->id;
                    if ($configuration['inventory'] && $product->controlsStock()) {
                        app(Inventory::class)->move($product->id, 'in', $stockQuantity, $stockCost,
                            'Compra #'.$purchase->id.' · item #'.$item->id, $userId, 'purchase-'.$purchase->id.'-'.$item->id, null, $acquisition);
                    } else {
                        $product->update(['reference_cost' => $stockCost]);
                        $referenceProducts[$product->id] = $product->id;
                    }
                    $total = bcadd($total, $lineTotal, 2);
                }
                if ($invoice !== null) {
                    $invoice['purchase_id'] = $purchase->id;
                    $invoice['lines'] = array_values($invoice['lines']);
                    OperationRecord::create(['version'=>0,'user_id'=>$userId,'action'=>'purchase_invoice','before'=>[],'after'=>$invoice,'created_at'=>now()]);
                }
                foreach ($referenceProducts as $productId) {
                    app(PricingService::class)->syncProduct($productId, $userId, 'purchase:'.$purchase->id);
                }
                Decimal::input($total, 2, 'items');
                $purchase->update(['total' => $total]);
                if (bccomp($total, '0', 2) === 0) {
                    $purchase->update(['paid' => true]);

                    return $purchase;
                }
                $finance = app(FinancialService::class);
                $entries = $finance->schedule([
                    'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:purchase-plan:'.$purchase->request_key)->toString(),
                    'purchase_id' => $purchase->id, 'supplier_id' => $purchase->supplier_id,
                    'direction' => 'out', 'description' => 'Compra #'.$purchase->id, 'category' => 'Compras',
                    'amount' => $total, 'due_date' => $data['due_date'] ?? ((bool) $data['paid'] ? now()->toDateString() : ''),
                    'installments' => $data['installments'] ?? 1,
                ], $userId);
                if ((bool) $data['paid']) {
                    foreach ($entries as $entry) {
                        $finance->pay($entry->id, $entry->amount, now()->toDateString(), $method, $userId,
                            Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:immediate-payment:'.$entry->request_key)->toString(),$choice['id']);
                    }
                }

                return $purchase->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = Purchase::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                OperationReplay::verify($existing, $data, $userId);
                return $existing;
            }

            throw $exception;
        }
    }
}
