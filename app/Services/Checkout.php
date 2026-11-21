<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Product;
use App\Models\OperationRecord;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Seller;
use App\Models\StockMovement;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class Checkout
{
    public function complete(array $data, int $userId): Sale
    {
        return $this->completeInternal($data, $userId);
    }

    /** No request-supplied flag can select this path: the agreement is reloaded from the database. */
    public function completeFromDocument(int $documentId, array $payment, int $userId): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($documentId, $payment, $userId) {
            app(CompanyOperations::class)->lock();
            $document = Document::lockForUpdate()->findOrFail($documentId);
            $saleData = array_merge($payment, [
                'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:document-sale:'.$document->request_key)->toString(),
                'customer_id' => $document->customer_id, 'items' => $document->items,
                'notes' => 'Origem: documento #'.$document->id,
            ]);
            if ($document->sale_id) {
                $sale = Sale::findOrFail($document->sale_id);
                OperationReplay::verify($sale, $saleData, $userId);
                return $sale;
            }
            $approved = ($document->type === 'quote' && $document->status === 'approved')
                || ($document->type === 'work_order' && $document->status === 'completed');
            if (! $approved) throw ValidationException::withMessages(['status' => 'O documento precisa estar aprovado ou concluído antes de gerar a venda.']);
            $assignedSeller = app(SellerAssignment::class)->forInvoice($document->id, $userId, $payment);
            $document->refresh();
            $sale = $this->completeInternal($saleData, $userId, $document, $assignedSeller);
            if (bccomp(bcsub($sale->total,app(SalePayments::class)->passedTotal($sale->id),2), $document->total, 2) !== 0) {
                throw ValidationException::withMessages(['items' => 'O valor da venda não corresponde ao documento aprovado.']);
            }
            $document->update(['sale_id' => $sale->id, 'status' => 'invoiced']);

            return $sale;
        }, 3);
    }

    /** Reload the persisted restaurant agreement; HTTP payloads cannot supply trusted prices. */
    public function completeFromRestaurant(int $orderId, array $payment, int $userId): Sale
    {
        return DB::connection('tenant')->transaction(function () use ($orderId, $payment, $userId) {
            app(CompanyOperations::class)->lock();
            app(OperationalModes::class)->require('restaurant');
            $reference = \App\Models\RestaurantOrder::findOrFail($orderId);
            \App\Models\RestaurantTable::lockForUpdate()->findOrFail($reference->restaurant_table_id);
            $order = \App\Models\RestaurantOrder::with('table')->lockForUpdate()->findOrFail($orderId);
            $items = $order->items()->where('status', '<>', 'cancelled')->orderBy('id')->get();
            if (!$order->sale_id && ($order->status !== 'open' || $items->isEmpty() || $items->contains(fn ($item) => $item->status !== 'delivered'))) {
                throw ValidationException::withMessages(['items' => 'Confira a comanda e entregue os itens antes de fechar a conta.']);
            }
            $data = array_merge($payment, [
                'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:restaurant:'.$order->request_key)->toString(),
                'customer_id' => $order->customer_id,
                'notes' => 'Comanda #'.$order->id.' · '.$order->table->name,
                'items' => $items->map(fn ($item) => array_merge($item->commercial_terms, [
                    'kind' => 'product', 'id' => $item->product_id, 'description' => $item->description,
                ]))->all(),
            ]);
            return $this->completeInternal($data, $userId, null, null, true, $orderId);
        }, 3);
    }

    private function completeInternal(array $data, int $userId, ?Document $document = null, ?Seller $assignedSeller = null, bool $restaurantSnapshot = false, ?int $restaurantOrderId = null): Sale
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($data, $userId, $document, $assignedSeller, $restaurantSnapshot, $restaurantOrderId) {
                $configuration = app(CompanyOperations::class)->lock();
                $existing = Sale::where('request_key', $data['request_key'])->first();
                if ($existing) {
                    if ($document && (int) $existing->document_id !== $document->id) {
                        throw ValidationException::withMessages(['request_key' => 'A chave já pertence a outra venda e não pode faturar este documento.']);
                    }
                    OperationReplay::verify($existing, $data, $userId);
                    return $existing;
                }
                $rules = app(CommercialRules::class);
                $seller = $document ? $assignedSeller : $rules->seller($userId,
                    isset($data['seller_id']) ? (int) $data['seller_id'] : null,
                    isset($data['salesperson_id']) ? (int) $data['salesperson_id'] : null);
                if (! $seller) throw new \LogicException('O vendedor do documento não foi resolvido pelo fluxo autorizado.');
                $split=array_key_exists('payments',$data);
                $historicalRestaurantPayment = $restaurantOrderId !== null && app(RestaurantPayments::class)->hasReceipts($restaurantOrderId)
                    && empty($data['payments']) && empty($data['payment_method']) && empty($data['payment_option']);
                $choice = $historicalRestaurantPayment ? app(RestaurantPayments::class)->headerChoice($restaurantOrderId)
                    : app(PaymentMethods::class)->resolve($split?($data['payments'][array_key_first($data['payments'])]['payment_option']??null):($data['payment_option']??null),(string)($data['payment_method']??'cash'));
                $method = FinancialService::paymentMethod($choice['type']);
                $sale = Sale::create(['request_key' => $data['request_key'], 'customer_id' => $data['customer_id'] ?? null, 'user_id' => $userId, 'seller_id' => $seller->user_id, 'salesperson_id' => $seller->id, 'seller_name' => ($document?->seller_name ?? $seller->name), 'document_id' => $document?->id, 'subtotal' => 0, 'discount_total' => 0, 'commission_total' => 0, 'commission_basis'=>$configuration['commission_basis'], 'payment_method' => $method, 'paid' => false, 'due_date' => $data['due_date'] ?? null, 'total' => 0, 'cost_total' => 0, 'notes' => $data['notes'] ?? null]);
                OperationReplay::record($sale, $data, $userId);
                app(PaymentMethods::class)->snapshot('sale_payment_method',$sale->id,$choice,$userId);
                $lines = $data['items'];
                if ($lines === [] || count($lines) > 200) {
                    throw ValidationException::withMessages(['items' => 'Informe de 1 a 200 itens.']);
                }
                usort($lines, fn ($a, $b) => strcmp($a['kind'], $b['kind']) ?: ($a['id'] <=> $b['id']));
                $genericRecipes = $restaurantSnapshot ? [] : app(RecipeInventory::class)->plansForProducts(
                    array_column(array_filter($lines, fn ($line) => ($line['kind'] ?? '') === 'product'), 'id'));
                $total = '0';
                $subtotal = '0';
                $discountTotal = '0';
                $commissionTotal = '0';
                $cost = '0';
                foreach ($lines as $i => $line) {
                    if (! in_array($line['kind'], ['product', 'service'], true)) {
                        throw ValidationException::withMessages(['items' => 'Tipo de item inválido.']);
                    }
                    $product = $line['kind'] === 'product';
                    $item = $product ? Product::where('active', true)->lockForUpdate()->findOrFail($line['id'])
                        : Product::resolveLegacyService((int) $line['id'], true);
                    app(CompanyOperations::class)->assertItem($item instanceof Product ? $item->type : 'service', $configuration);
                    $detail = '';
                    if (!$document && isset($line['measure_unit']) && $item instanceof Product) {
                        [$line['quantity'], , $detail] = MeasureUnits::convert($item, $configuration, $line, 'sale');
                    }
                    $terms = ($document || $restaurantSnapshot)
                        ? $rules->fromDocument($item, $line, $document ? (int) $document->commercial_version : 1)
                        : $rules->quote($item, $line);
                    $qty = $terms['quantity'];
                    $unitCost = $item instanceof Product ? ($configuration['inventory'] ? $item->pricingCost() : (string)$item->reference_cost) : $item->cost;
                    $amount = $terms['total'];
                    $saleItem = SaleItem::create(array_merge(['fiscal_kind' => $item instanceof Product ? $item->type : 'service', 'sale_id' => $sale->id, 'product_id' => $product ? $item->id : null,
                        'service_id' => $product ? null : (int) $line['id'],
                        'description' => ($document || $restaurantSnapshot) ? ($line['description'] ?? $item->name) : $item->name.$detail, 'unit_cost' => $unitCost], $terms));
                    if ($restaurantSnapshot && !empty($line['_restaurant_catalog']['addons'])) {
                        OperationRecord::create(['action' => 'restaurant_sale_options', 'version' => 1, 'user_id' => $userId,
                            'before' => [], 'after' => ['sale_id' => $sale->id, 'sale_item_id' => $saleItem->id,
                                'addons' => $line['_restaurant_catalog']['addons'],
                                'recipe_revision' => (int)($line['_restaurant_catalog']['recipe']['revision'] ?? 0)], 'created_at' => now()]);
                    }
                    $subtotal = bcadd($subtotal, $terms['gross_total'], 2);
                    $discountTotal = bcadd($discountTotal, $terms['discount_amount'], 2);
                    $commissionTotal = bcadd($commissionTotal, $terms['commission_amount'], 2);
                    $lineCost = bcmul($qty, $unitCost, 8);
                    $recipe = $product ? ($restaurantSnapshot ? ($line['_restaurant_catalog']['recipe'] ?? null) : ($genericRecipes[$item->id] ?? null)) : null;
                    if (!$restaurantSnapshot && ($recipe['requires_choices'] ?? false)) throw ValidationException::withMessages(['items' => 'Este produto tem escolhas obrigatórias. Registre o pedido na comanda para selecionar os adicionais.']);
                    if ($recipe !== null && !is_array($recipe)) throw ValidationException::withMessages(['recipe' => 'A ficha registrada está incompleta. Revise o atendimento.']);
                    $consumption = $product && $recipe !== null ? app(RecipeInventory::class)->consume($sale, $saleItem, $item, $qty, $recipe, $userId, $configuration)
                        : ['handled_base' => false, 'cost' => '0'];
                    if ($configuration['inventory'] && $product && $item->controlsStock() && !$consumption['handled_base']) {
                        $inventory = app(Inventory::class);
                        $movementKey = 'sale-'.$sale->id.'-'.$i;
                        $inventory->move($item->id, 'sale', $qty, $unitCost, 'Venda #'.$sale->id, $userId, $movementKey, $sale->id, null, $saleItem->id);
                        $lineCost = $inventory->movementValue($movementKey) ?? $lineCost;
                    }
                    $lineCost = $consumption['handled_base'] ? $consumption['cost'] : bcadd($lineCost, $consumption['cost'], 8);
                    if ($consumption['has_ingredients'] ?? false) $saleItem->update(['unit_cost' => Decimal::round(bcdiv($lineCost, $qty, 8), 4)]);
                    $total = bcadd($total, $amount, 2);
                    $cost = bcadd($cost, $lineCost, 8);
                }
                $cost = Decimal::round($cost, 4);
                Decimal::input($total, 2, 'items');
                Decimal::input($cost, 4, 'items', '0', '999999999999.9999');
                Decimal::input($subtotal, 2, 'items');
                Decimal::input($discountTotal, 2, 'items');
                Decimal::input($commissionTotal, 2, 'items');
                $sale->update(['total' => $total, 'cost_total' => $cost, 'subtotal' => $subtotal,
                    'discount_total' => $discountTotal, 'commission_total' => $commissionTotal]);
                if ($restaurantOrderId !== null && app(RestaurantPayments::class)->hasReceipts($restaurantOrderId)) {
                    app(RestaurantPayments::class)->settle($restaurantOrderId, $sale, $data, $userId);
                    return $sale->fresh();
                }
                if (!$split && (!empty($data['card_brand']) || (in_array($method,['credit','debit'],true)&&app(CardFees::class)->options($method)))){
                    $data['payments']=[array_merge($data,['payment_option'=>$choice['id'],'amount'=>$total])];$split=true;
                }
                if ($split) {
                    app(SalePayments::class)->record($sale,$data['payments'],$userId);
                    return $sale->fresh();
                }
                if (bccomp($total, '0', 2) === 0) {
                    $sale->update(['paid' => true]);

                    return $sale;
                }
                $finance = app(FinancialService::class);
                $entries = $finance->schedule([
                    'request_key' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:sale-plan:'.$sale->request_key)->toString(),
                    'sale_id' => $sale->id, 'direction' => 'in', 'description' => 'Venda #'.$sale->id,
                    'category' => 'Vendas', 'amount' => $total,
                    'due_date' => $data['due_date'] ?? ((bool) $data['paid'] ? now()->toDateString() : ''),
                    'installments' => $data['installments'] ?? 1,
                ], $userId);
                if ((bool) $data['paid']) {
                    foreach ($entries as $entry) {
                        $finance->pay($entry->id, $entry->amount, now()->toDateString(), $method, $userId,
                            Uuid::uuid5(Uuid::NAMESPACE_URL, 'vendra:immediate-payment:'.$entry->request_key)->toString(),$choice['id']);
                    }
                }

                return $sale->fresh();
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request may win the unique request_key insert. The losing
            // transaction has rolled back; use a current read, including in a parent transaction.
            $existing = Sale::where('request_key', $data['request_key'])->lockForUpdate()->first();
            if ($existing) {
                if ($document && (int) $existing->document_id !== $document->id) {
                    throw ValidationException::withMessages(['request_key' => 'A chave já pertence a outra venda e não pode faturar este documento.']);
                }
                OperationReplay::verify($existing, $data, $userId);
                return $existing;
            }

            throw $exception;
        }
    }

    public function cancel(int $id, int $userId, bool $refundConfirmed = false): void
    {
        DB::connection('tenant')->transaction(function () use ($id, $userId, $refundConfirmed) {
            app(CompanyOperations::class)->lock();
            InstallerAccess::authorizedActor($userId, 'sales.cancel');
            $sale = Sale::lockForUpdate()->findOrFail($id);
            if ($sale->status === 'cancelled') {
                return;
            }
            app(FinancialService::class)->cancelSale($sale->id, $userId, $refundConfirmed);
            $feeIds=[];foreach(app(SalePayments::class)->rows($sale->id) as $part)$feeIds=array_merge($feeIds,$part['fee_entry_ids']??[]);
            foreach(\App\Models\FinancialEntry::whereIn('id',$feeIds)->orderBy('id')->lockForUpdate()->get() as $expense){if(bccomp($expense->paid_amount,'0',2)===0)$expense->update(['cancelled_at'=>now()]);}
            $baseUnitCosts = OperationRecord::where('action', 'restaurant_recipe_consumed')->where('after->sale_id', $sale->id)->get()
                ->filter(fn ($record) => isset($record->after['base_unit_cost']))
                ->mapWithKeys(fn ($record) => [$record->after['sale_item_id'] => $record->after['base_unit_cost']]);
            foreach ($sale->items()->whereNotNull('product_id')->orderBy('product_id')->get() as $line) {
                // Historical participation, not today's product type, determines a stock return.
                $hadStock = StockMovement::where('sale_id', $sale->id)->where('product_id', $line->product_id)->where('type', 'sale')->exists();
                if (! $hadStock) {
                    continue;
                }
                $product = Product::lockForUpdate()->findOrFail($line->product_id);
                if (! $product->controlsStock()) {
                    throw ValidationException::withMessages(['product_id' => 'Reative o controle de estoque de '.$product->name.' antes de estornar a venda que originalmente baixou seu saldo.']);
                }
                app(Inventory::class)->move($line->product_id, 'return', $line->quantity, $baseUnitCosts->get($line->id, $line->unit_cost), 'Estorno venda #'.$sale->id, $userId, 'cancel-'.$line->id, $sale->id, app(Inventory::class)->saleValueForItem($sale->id, $line->id));
            }
            app(RecipeInventory::class)->cancel($sale, $userId);
            $sale->update(['status' => 'cancelled', 'paid' => false]);
        }, 3);
    }
}
