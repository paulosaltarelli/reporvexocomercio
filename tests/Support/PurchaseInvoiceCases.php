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
    public function test_v1_purchases_accept_manual_stock_and_reject_fiscal_inputs_and_xml(): void
    {
        config(['fiscal.product_invoices_enabled'=>false]);
        $supplier = Supplier::create(['name'=>'Fornecedor comercial']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->get('/app/a/purchases/new')->assertOk()->assertSee('custo efetivo')
            ->assertDontSee('Nota fiscal de entrada')->assertDontSee('fiscal_detail')->assertDontSee('prévia do XML');
        $this->post('/app/a/purchases/preview-xml')->assertNotFound();
        $data = ['request_key'=>(string) Str::uuid(),'supplier_id'=>$supplier->id,
            'purchased_on'=>now()->toDateString(),'due_date'=>now()->toDateString(),'paid'=>0,'payment_method'=>'invoice',
            'items'=>[['product_id'=>$this->product->id,'quantity'=>'3','unit_cost'=>'12.00']]];
        $this->post('/app/a/purchases',$data+['invoice'=>['number'=>'18']])->assertSessionHasErrors('invoice');
        $fiscal = $data;
        $fiscal['items'][0]['fiscal_detail'] = '{"taxes":{}}';
        $this->post('/app/a/purchases',$fiscal)->assertSessionHasErrors('items');
        $this->post('/app/a/purchases',$data)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $purchase = Purchase::sole();
        $this->assertSame('36.00',$purchase->entries()->sole()->amount);
        $this->assertSame('3.000',$this->product->fresh()->quantity);
    }

    public function test_inbound_reform_invoice_can_be_received_while_issuance_is_off(): void
    {
        config(['fiscal.product_invoices_enabled'=>false,'fiscal.purchase_invoices_enabled'=>true]);
        \App\Models\BusinessProfile::forceCreate(['id'=>1, 'legal_name'=>'Loja RTC', 'document'=>'11444777000161']);
        $supplier = Supplier::create(['name'=>'Fornecedor RTC', 'document'=>'11222333000181']);
        $this->withSession(['tenant_id'=>$this->a->id,'user_id'=>$this->user->id]);
        $this->get('/app/a/purchases/new')->assertOk()->assertSee('name="invoice_xml"', false)->assertSee('IBS estadual');
        $this->assertFalse(\App\Fiscal\ProductInvoiceGate::enabled());
        $xml = <<<'XML'
<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe35261111222333000181550010000000181123456781">
<ide><mod>55</mod><serie>1</serie><nNF>18</nNF><dhEmi>2026-11-01T10:00:00-03:00</dhEmi></ide>
<emit><CNPJ>11222333000181</CNPJ><xNome>Fornecedor RTC</xNome></emit><dest><CNPJ>11444777000161</CNPJ></dest>
<det nItem="1"><prod><cProd>X</cProd><xProd>Produto</xProd><uCom>UN</uCom><qCom>1</qCom><vUnCom>10.00</vUnCom><vProd>10.00</vProd></prod>
<imposto><IBSCBS><CST>000</CST><cClassTrib>000001</cClassTrib><gIBSCBS><gIBSUF><vIBSUF>0.01</vIBSUF></gIBSUF><gIBSMun><vIBSMun>0.00</vIBSMun></gIBSMun><vIBS>0.01</vIBS><gCBS><vCBS>0.09</vCBS></gCBS></gIBSCBS></IBSCBS></imposto></det>
<total><ICMSTot><vNF>10.00</vNF></ICMSTot><IBSCBSTot><gIBS><gIBSUF><vIBSUF>0.01</vIBSUF></gIBSUF><gIBSMun><vIBSMun>0.00</vIBSMun></gIBSMun><vIBS>0.01</vIBS></gIBS><gCBS><vCBS>0.09</vCBS></gCBS></IBSCBSTot><vNFTot>10.00</vNFTot></total></infNFe></NFe>
XML;
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('entrada.xml', $xml);
        $this->post('/app/a/purchases/preview-xml', ['invoice_xml'=>$file])->assertRedirect('/app/a/purchases/new')
            ->assertSessionHasNoErrors()->assertSessionHasInput('supplier_id', $supplier->id)
            ->assertSessionHasInput('items.0.taxes.cbs.composition', 'reported');
        $input = session()->getOldInput();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0, Purchase::count());
        $draft = $input['items'][0];
        $date = '2026-11-02';
        $this->travelTo(\Carbon\Carbon::parse($date.' 12:00:00'));
        $purchase = ['request_key'=>(string)Str::uuid(),'supplier_id'=>$input['supplier_id'],
            'purchased_on'=>$date,'due_date'=>$date,'paid'=>0,'payment_method'=>'invoice',
            'invoice'=>$input['invoice'], 'items'=>[['product_id'=>$this->product->id,
                'quantity'=>$draft['quantity'],'unit_cost'=>$draft['unit_cost'],'rtc_codes'=>$draft['rtc_codes'],
                'fiscal_detail'=>json_encode(['adjustments'=>$draft['adjustments'],'taxes'=>$draft['taxes']])]]];
        $this->post('/app/a/purchases', $purchase)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $entry = Purchase::sole();
        $this->assertSame('10.00', $entry->entries()->sole()->amount);
        $this->assertSame('10.0000000', app(Inventory::class)->totalValue());
        $this->get('/app/a/purchases/'.$entry->id)->assertOk()->assertSee('CBS R$ 0,09')->assertSee('Destaque informativo')->assertSee('Classificação IBS/CBS 000001');
    }

    public function test_supplier_invoice_preserves_total_stock_value_and_blocks_duplicate_receipt(): void
    {
        config(['fiscal.product_invoices_enabled'=>true]);
        $supplier = Supplier::create(['name' => 'Fornecedor NF']);
        $id = $this->product->id;
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id]);
        $this->get('/app/a/purchases/new')->assertOk()->assertSee('Nota fiscal de entrada')
            ->assertSee('name="invoice_xml"', false);
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
        config(['fiscal.product_invoices_enabled'=>false]);
        $this->get("/app/a/purchases/{$entry->id}")->assertOk()->assertDontSee('Crédito recuperável')->assertDontSee('Nota 18');
        app(TenantConnection::class)->connect($this->a);
        $this->assertTrue(\App\Models\OperationRecord::where('action','purchase_invoice')->where('after->purchase_id',$entry->id)->exists());
    }
}
