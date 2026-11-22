<?php

namespace Tests\Support;

use App\Services\CompanyOperations;
use App\Services\TenantConnection;

trait ProductLabelCases
{
    public function test_product_labels_print_current_prices_in_requested_quantities_with_exact_existing_identifiers(): void
    {
        $this->product->update(['barcode'=>'7891234567895']);
        $internal = $this->product->replicate();
        $internal->fill(['name'=>'Farinha integral', 'sku'=>'FAR-01', 'barcode'=>null, 'unit'=>'KG', 'sale_price'=>'12.50'])->save();
        $this->withSession(['tenant_id'=>$this->a->id, 'user_id'=>$this->user->id, 'auth_version'=>0]);
        $qty = [$this->product->id=>2, $internal->id=>1];
        $this->get('/app/a/products/labels?'.http_build_query(['q'=>'Farinha', 'qty'=>$qty]))->assertOk()
            ->assertSee($this->product->name)->assertSee($internal->name);
        $printed = $this->get('/app/a/products/labels/print?'.http_build_query(['format'=>'roll', 'qty'=>$qty]))
            ->assertOk()->assertSee('12,50')->assertSee('FAR-01')->assertSee('7891234567895');
        $this->assertSame('EAN13', $printed->viewData('labels')[0]['barcode']['type']);
        $this->assertSame('C128', $printed->viewData('labels')[1]['barcode']['type']);
        $this->assertSame(3, substr_count($printed->getContent(), '<article class="shelf-label">'));
        $this->assertSame(3, substr_count($printed->getContent(), '<svg class="barcode"'));
        app(TenantConnection::class)->connect($this->a);
        $internal->update(['sku'=>$this->product->barcode]);
        $res = $this->get('/app/a/products/labels/print?'.http_build_query(['qty'=>$qty]))->assertOk();
        $this->assertSame($this->product->sku, $res->viewData('labels')[0]['barcode']['code']);
        $this->assertNull($res->viewData('labels')[1]['barcode']);
        $res->assertSee('Consulta pelo nome');
        app(TenantConnection::class)->connect($this->a);
        $internal->update(['barcode'=>str_repeat('A', 80), 'sku'=>'FAR-02']);
        $res = $this->get('/app/a/products/labels/print?'.http_build_query(['qty'=>[$internal->id=>1]]))->assertOk();
        $this->assertSame('FAR-02', $res->viewData('labels')[0]['barcode']['code']);
    }

    public function test_product_labels_validate_catalog_scope_count_and_permission(): void
    {
        $this->withSession(['tenant_id'=>$this->a->id, 'user_id'=>$this->user->id, 'auth_version'=>0]);
        $route = '/app/a/products/labels/print?';
        foreach ([[], [999999=>1], [$this->product->id=>201], [$this->product->id=>100, 999999=>101], [$this->product->id=>-1], ['wrong'=>2]] as $qty) {
            $this->getJson($route.http_build_query(['qty'=>$qty]))->assertUnprocessable();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->product->update(['type'=>'service']);
        $this->getJson($route.http_build_query(['qty'=>[$this->product->id=>1]]))->assertUnprocessable();
        $this->get('/app/b/products/labels')->assertRedirect('/app/b/login');
        app(TenantConnection::class)->connect($this->a);
        app(CompanyOperations::class)->save($this->operationPayload(['segment'=>'supermarket','operation_modes'=>['supermarket'],'default_operation'=>'supermarket']), $this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $this->withSession(['tenant_id'=>$this->a->id, 'user_id'=>$cashier->id, 'auth_version'=>0]);
        $this->get('/app/a/products/labels')->assertForbidden();
        $this->get('/app/a/products/labels/print')->assertForbidden();
    }
}
