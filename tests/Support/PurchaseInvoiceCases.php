<?php

namespace Tests\Support;

use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Checkout;
use App\Services\Inventory;
use App\Services\TenantConnection;
use Illuminate\Support\Str;

trait PurchaseInvoiceCases
{
    public function test_supplier_invoice_preserves_total_stock_value_and_blocks_duplicate_receipt(): void
    {
        $supplier = Supplier::create(['name' => 'Fornecedor NF']);
        $id = $this->product->id;
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->post("/app/a/products/$id/units", ['code' => 'CX', 'factor' => '1000', 'purpose' => 'purchase', 'base' => 'UN'])->assertSessionHasNoErrors();
        $date = now()->toDateString();
        $purchase = ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'purchased_on' => $date, 'paid' => 0, 'payment_method' => 'invoice', 'due_date' => $date,
            'invoice' => ['number' => '18', 'series' => '1', 'issued_on' => $date, 'declared_total' => '126.00'],
            'items' => [['product_id' => $id, 'quantity' => '100', 'unit_cost' => '1.24', 'measure_unit' => 'CX', 'factor_seen' => '1000.000000',
                'fiscal_detail' => json_encode(['taxes' => ['ipi' => ['amount' => '2.00', 'composition' => 'added', 'treatment' => 'credit']]])]]];
        $this->post('/app/a/purchases', $purchase)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $entry = Purchase::firstOrFail();
        $this->assertSame('126.00', $entry->entries()->firstOrFail()->amount);
        $this->assertSame('100000.000', $this->product->fresh()->quantity);
        $this->assertSame('0.0012', $this->product->fresh()->average_cost);
        $this->assertSame('124.0000000', app(Inventory::class)->totalValue());
        $this->get("/app/a/purchases/{$entry->id}")->assertOk()->assertSee('Crédito recuperável')->assertSee('124,00');
        $purchase['request_key'] = (string) Str::uuid();
        $this->post('/app/a/purchases', $purchase)->assertSessionHasErrors('invoice.number');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Purchase::count());
        $first = app(Checkout::class)->complete($this->saleData('1'), $this->user->id);
        $sale = app(Checkout::class)->complete($this->saleData('99999'), $this->user->id);
        $this->assertSame('124.0000', bcadd($first->cost_total, $sale->cost_total, 4));
        $this->assertSame('0.0000000', app(Inventory::class)->totalValue());
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        app(Checkout::class)->cancel($first->id, $this->user->id, true);
        $this->assertSame('124.0000000', app(Inventory::class)->totalValue());
    }
}
