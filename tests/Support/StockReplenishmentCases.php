<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\User;
use App\Services\Checkout;
use App\Services\CompanyOperations;
use App\Services\TenantConnection;

trait StockReplenishmentCases
{
    public function test_stock_replenishment_uses_completed_sales_in_the_last_sixty_calendar_days_and_purchase_units(): void
    {
        $this->product->update(['unit' => 'KG', 'minimum_stock' => '1001.000']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $unitUrl = '/app/a/products/'.$this->product->id.'/units';
        foreach ([['T', '1000', 'purchase'], ['CX', '12', 'purchase'], ['L', '5', 'sale']] as [$code, $factor, $purpose]) {
            $this->post($unitUrl, compact('code', 'factor', 'purpose') + ['base' => 'KG'])->assertSessionHasNoErrors();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->move('in', '2', '0.50');
        $sale = app(Checkout::class)->complete($this->saleData(), $this->user->id);
        $sale->update(['created_at' => today()->subDays(59)]);
        $url = '/app/a/stock/replenishment';
        $result = $this->get($url)->assertOk()->assertSee('SKU-1')->assertSee('1 T = 1.000 KG');
        $row = $result->viewData('products')->sole();
        $this->assertSame('1000.000', $row['required']);
        $this->assertSame('1.000', $row['equivalents']['T']['quantity']);
        $this->assertNull($row['equivalents']['CX']['quantity']);
        $this->assertArrayNotHasKey('L', $row['equivalents']);

        app(TenantConnection::class)->connect($this->a);
        $sale->items()->update(['fiscal_kind' => 'service']);
        $this->get($url)->assertOk()->assertDontSee('SKU-1');
        app(TenantConnection::class)->connect($this->a);
        $sale->items()->update(['fiscal_kind' => null]);
        $this->get($url)->assertOk()->assertSee('SKU-1');
        app(TenantConnection::class)->connect($this->a);
        $sale->update(['created_at' => today()->subDays(59)->subSecond()]);
        $this->get($url)->assertOk()->assertDontSee('SKU-1')->assertSee('Incluir produtos sem vendas recentes');
        $this->get($url.'?sales=all&q=SKU-1')->assertOk()->assertSee('SKU-1');
        app(TenantConnection::class)->connect($this->a);
        $sale->update(['created_at' => now()]);
        app(Checkout::class)->cancel($sale->id, $this->user->id, true);
        $this->get($url)->assertOk()->assertDontSee('SKU-1');
        $this->get($url.'?sales=all&q=%25')->assertOk()->assertDontSee('SKU-1');
    }

    public function test_stock_replenishment_pages_only_active_controlled_products_with_a_deficit(): void
    {
        $this->product->update(['quantity' => '-3.500', 'minimum_stock' => '0']);
        $attributes = ['product_group_id' => $this->product->product_group_id, 'sale_price' => '10', 'unit' => 'UN', 'minimum_stock' => '1'];
        foreach (range(1, 25) as $index) {
            Product::create($attributes + ['sku' => 'B'.$index, 'name' => sprintf('Bateria %02d', $index)]);
        }
        foreach (['Sem saldo a repor' => ['quantity' => '1'], 'Inativo' => ['active' => false],
            'Serviço avulso' => ['type' => 'service'], 'Sem controle' => ['controls_stock' => false]] as $name => $extra) {
            Product::create($extra + $attributes + ['name' => $name, 'sku' => $name]);
        }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $url = '/app/a/stock/replenishment?sales=all';
        $page = $this->get($url)->assertOk()->viewData('products');
        $this->assertSame(26, $page->total());
        $this->assertCount(25, $page->items());
        $this->assertStringContainsString('sales=all', $page->nextPageUrl());
        $row = $this->get($url.'&page=2')->assertOk()->viewData('products')->sole();
        $this->assertSame('3.500', $row['required']);
        $this->assertSame('SKU-1', $row['product']->sku);
    }

    public function test_stock_replenishment_is_scoped_to_company_and_guarded_by_stock_permission_and_inventory_setting(): void
    {
        $this->product->update(['minimum_stock' => '5']);
        $operator = $this->profiledOperator($this->accessProfile(['products.view']));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $operator->id])
            ->get('/app/a/stock/replenishment')->assertForbidden();
        app(TenantConnection::class)->connect($this->b);
        $other = User::create(['name' => 'Operador B', 'email' => 'other@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $group = ProductGroup::create(['name' => 'Grupo B', 'margin' => '20']);
        Product::create(['sku' => 'EXCLUSIVO-B', 'name' => 'Produto B', 'product_group_id' => $group->id, 'sale_price' => '1', 'minimum_stock' => '2']);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $other->id])
            ->get('/app/b/stock/replenishment?sales=all')->assertOk()->assertSee('EXCLUSIVO-B')->assertDontSee('SKU-1');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id])
            ->get('/app/a/stock/replenishment?sales=all')->assertOk()->assertSee('SKU-1')->assertDontSee('EXCLUSIVO-B');
        app(TenantConnection::class)->connect($this->a);
        $operations = app(CompanyOperations::class);
        $operations->save($this->operationPayload(['version' => \App\Models\CompanyOperation::find(1)->version, 'inventory' => false]), $this->user->id);
        $this->get('/app/a/stock/replenishment')->assertForbidden();
    }
}
