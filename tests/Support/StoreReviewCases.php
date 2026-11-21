<?php

namespace Tests\Support;

use App\Models\CompanyOperation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\OperationRecord;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\Documents;
use App\Services\FinancialService;
use App\Services\PaymentMethods;
use App\Services\PurchaseService;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait StoreReviewCases
{
    public function test_store_review_purchase_finance_context_keeps_its_origin_and_pagination_without_exposing_another_tenant(): void
    {
        $supplier = Supplier::create(['name' => 'Fornecedor de conferência']);
        $input = ['request_key' => (string) Str::uuid(), 'supplier_id' => $supplier->id,
            'purchased_on' => today()->toDateString(), 'paid' => false, 'payment_method' => 'invoice',
            'installments' => 24, 'due_date' => today()->toDateString(),
            'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '24']]];
        $purchase = app(PurchaseService::class)->receive($input, $this->user->id);
        $other = app(PurchaseService::class)->receive(array_replace($input, ['request_key' => (string) Str::uuid(),
            'installments' => 1, 'items' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '7']]]), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get('/app/a/purchases/'.$purchase->id)->assertOk()
            ->assertSee(route('finance', ['company' => 'a', 'purchase' => $purchase->id]), false);
        $response = $this->get('/app/a/finance?purchase='.$purchase->id)->assertOk()
            ->assertSee('Parcelas da compra #'.$purchase->id)->assertSee('Voltar à compra #'.$purchase->id)
            ->assertSee('name="purchase" value="'.$purchase->id.'"', false)->assertViewHas('payable', '31.00');
        $entries = $response->viewData('entries');
        $this->assertSame(24, $entries->total());
        $this->assertSame([$purchase->id], $entries->pluck('purchase_id')->unique()->all());
        $this->assertStringContainsString('purchase='.$purchase->id, $entries->url(2));
        $this->get('/app/a/finance?purchase='.$purchase->id.'&page=2')->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->count() === 4 && $rows->pluck('purchase_id')->unique()->all() === [$purchase->id]);
        $this->get('/app/a/finance?purchase='.$other->id)->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->total() === 1 && $rows->first()->purchase_id === $other->id);
        $this->getJson('/app/a/finance?purchase=999999')->assertUnprocessable()->assertJsonValidationErrors('purchase');
        app(TenantConnection::class)->connect($this->a);
        $sale = app(\App\Services\Checkout::class)->complete($this->saleData(), $this->user->id);
        $this->getJson('/app/a/finance?purchase='.$purchase->id.'&sale='.$sale->id)->assertUnprocessable()->assertJsonValidationErrors('purchase');
        $this->get('/app/a/finance?sale='.$sale->id)->assertOk()
            ->assertViewHas('entries', fn ($rows) => $rows->isNotEmpty() && $rows->pluck('sale_id')->unique()->all() === [$sale->id]);
        app(TenantConnection::class)->connect($this->b);
        $actor = User::create(['name' => 'Admin B', 'email' => 'review-b@example.test', 'password' => 'local-qa-password', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $actor->id, 'auth_version' => 0]);
        $this->getJson('/app/b/finance?purchase='.$purchase->id)->assertUnprocessable()->assertJsonValidationErrors('purchase');
    }

    public function test_store_review_finance_loads_historical_method_labels_in_one_query_and_preserves_the_original_names(): void
    {
        $methods = app(PaymentMethods::class);
        $methods->save(['id' => 'pix', 'version' => 0, 'label' => 'Pix no caixa QA', 'type' => 'pix', 'active' => 1], $this->user->id);
        $finance = app(FinancialService::class);
        $entry = $finance->schedule(['request_key' => (string) Str::uuid(), 'direction' => 'in', 'amount' => '20',
            'description' => 'Recebimentos parciais QA', 'category' => 'Serviços', 'due_date' => today()->toDateString()], $this->user->id)->first();
        for ($index = 0; $index < 20; $index++) $finance->pay($entry->id, '1', today()->toDateString(), 'pix', $this->user->id, (string) Str::uuid(), 'pix');
        $methods->save(['id' => 'pix', 'version' => CompanyOperation::findOrFail(1)->version,
            'label' => 'Nome atual Pix QA', 'type' => 'pix', 'active' => 1], $this->user->id);
        $queries = 0;
        $counting = true;
        DB::listen(function ($query) use (&$queries, &$counting) {
            if ($counting && preg_match('/^select\b/i', ltrim($query->sql)) && in_array('payment_method_used', $query->bindings, true)) $queries++;
        });
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        try { $response = $this->get('/app/a/finance')->assertOk(); }
        finally { $counting = false; }
        $this->assertSame(1, $queries);
        $this->assertCount(20, $response->viewData('payments'));
        $this->assertSame(['Pix no caixa QA'], array_values(array_unique($response->viewData('paymentLabels'))));
    }

    public function test_store_review_stock_copy_matches_policy_and_customer_choices_disambiguate_only_when_permitted(): void
    {
        $operations = app(CompanyOperations::class);
        $this->product->update(['unit' => 'KG']);
        $this->move('in', '12.5', '10');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        foreach (['block' => 'Saídas são bloqueadas se o saldo ficar negativo.', 'warn' => 'Saídas com saldo negativo geram um alerta.',
            'allow' => 'Saídas podem gerar saldo negativo, conforme a configuração da empresa.'] as $policy => $copy) {
            app(TenantConnection::class)->connect($this->a);
            $operations->save(array_replace($operations->current(), ['version' => CompanyOperation::find(1)?->version ?? 0, 'negative_stock' => $policy]), $this->user->id);
            $this->get('/app/a/stock')->assertOk()->assertSee($copy)->assertSee('Saldo 12,500 KG')
                ->assertSee('Quantidade na unidade de estoque')->assertDontSee('nunca permitem saldo negativo');
        }
        app(TenantConnection::class)->connect($this->a);
        Customer::create(['name' => 'Alex de Souza', 'document' => '111.444.777-35', 'phone' => '(35) 99999-1111']);
        Customer::create(['name' => 'Alex de Souza', 'document' => '07.396.600/0001-60', 'phone' => '(35) 99999-2222']);
        $this->get('/app/a/documents/new?type=work_order')->assertOk()->assertSee('CPF/CNPJ 111.444.777-35')
            ->assertSee('CPF/CNPJ 07.396.600/0001-60')->assertSee('Tel. (35) 99999-1111')->assertSee('Tel. (35) 99999-2222');
        app(TenantConnection::class)->connect($this->a);
        $profiles = app(\App\Access\AccessProfiles::class);
        $id = $profiles->save(['version' => CompanyOperation::findOrFail(1)->version, 'name' => 'Só atendimento QA',
            'permissions' => ['documents.create']], $this->user->id);
        $actor = User::create(['name' => 'Atendimento', 'email' => 'review-operator@example.test', 'password' => 'local-qa-password', 'role' => 'operator']);
        DB::connection('tenant')->transaction(fn () => $profiles->assign($actor->id, 'operator', $id, $this->user->id));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $actor->id, 'auth_version' => 0]);
        $this->get('/app/a/documents/new?type=work_order')->assertOk()->assertSee('Alex de Souza')
            ->assertDontSee('111.444.777-35')->assertDontSee('99999-1111')->assertDontSee('07.396.600/0001-60');
    }

    private function reviewDocumentPayload(): array
    {
        return ['type' => 'work_order', 'request_key' => (string) Str::uuid(), 'asset' => 'Equipamento QA',
            'notes' => 'Diagnóstico original', 'items' => [['kind' => 'product', 'id' => $this->product->id,
                'quantity' => '1.000', 'unit_price' => '100.00', 'discount_percent' => '0.00', 'catalog_price_seen' => '100.00']]];
    }

    public function test_store_review_document_replay_verifies_actor_and_payload_without_repricing_the_original_request(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload(['inventory' => false]), $this->user->id);
        $service = app(Documents::class);
        $payload = $this->reviewDocumentPayload();
        $document = $service->create($payload, $this->user->id);
        $equivalent = $payload;
        $equivalent['items'][0] = ['kind' => 'product', 'id' => (string) $this->product->id, 'quantity' => 1,
            'unit_price' => 100, 'discount_percent' => 0, 'catalog_price_seen' => 100];
        $this->assertSame($document->id, $service->create($equivalent, $this->user->id)->id);
        $this->product->update(['sale_price' => '150']);
        $this->assertSame($document->id, $service->create($payload, $this->user->id)->id);
        $actor = User::create(['name' => 'Outro responsável', 'email' => 'document-review@example.test', 'password' => 'local-qa-password', 'role' => 'admin']);
        $customer = Customer::create(['name' => 'Cliente diferente']);
        foreach (['notes' => 'Outro diagnóstico', 'asset' => 'Outro equipamento', 'type' => 'quote', 'customer_id' => $customer->id,
            'items' => [array_replace($payload['items'][0], ['quantity' => '2'])]] as $key => $value) {
            try { $service->create(array_replace($payload, [$key => $value]), $this->user->id); $this->fail('Envio modificado reutilizou a OS.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('request_key', $error->errors()); }
        }
        try { $service->create($payload, $actor->id); $this->fail('Outro ator reutilizou a OS.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('request_key', $error->errors()); }
        $this->assertSame(1, Document::count());
        $this->assertSame(1, OperationRecord::where('action', 'documents_request')->count());
        $this->assertSame('100.00', $document->fresh()->total);
        $this->assertSame('Diagnóstico original', $document->fresh()->notes);
        $this->assertSame(0, \App\Models\Sale::count());
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    public function test_store_review_empty_work_order_replays_with_the_same_normalized_empty_items(): void
    {
        $service = app(Documents::class);
        $payload = ['type' => 'work_order', 'request_key' => (string) Str::uuid(), 'asset' => 'Recepção sem valores'];
        $document = $service->create($payload, $this->user->id);
        $this->assertSame($document->id, $service->create($payload + ['items' => []], $this->user->id)->id);
        $this->assertSame(1, Document::count());
        $this->assertSame(1, OperationRecord::where('action', 'documents_request')->count());
    }

    public function test_store_review_legacy_document_without_request_snapshot_still_opens_edits_and_invoices_but_rejects_create_replay(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload(['inventory' => false]), $this->user->id);
        $service = app(Documents::class);
        $payload = $this->reviewDocumentPayload();
        $document = $service->create($payload, $this->user->id);
        // Fixture for a document written before creation request snapshots existed.
        DB::connection('tenant')->table('company_operation_audits')->where('action', 'documents_request')->where('after->id', $document->id)->delete();
        try { $service->create($payload, $this->user->id); $this->fail('O envio legado foi considerado verificável sem snapshot.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('request_key', $error->errors()); }
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get('/app/a/documents/'.$document->id)->assertOk();
        app(TenantConnection::class)->connect($this->a);
        $service->updateDraft($document->id, ['expected_hash' => $service->editHash($document->fresh()),
            'asset' => 'Equipamento revisado', 'notes' => 'Legado permanece editável', 'items' => $payload['items']], $this->user->id);
        $service->transition($document->id, 'in_progress');
        $service->transition($document->id, 'completed');
        $sale = app(\App\Services\Checkout::class)->completeFromDocument($document->id,
            ['payment_method' => 'invoice', 'paid' => false, 'installments' => 1, 'due_date' => today()->addDay()->toDateString()], $this->user->id);
        $this->assertSame('100.00', $sale->total);
        $this->assertSame('invoiced', $document->fresh()->status);
        $this->assertSame(1, Document::count());
        $this->assertSame(0, OperationRecord::where('action', 'documents_request')->count());
    }
}
