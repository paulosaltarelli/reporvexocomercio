<?php

namespace Tests\Support;

use App\Models\FinancialEntry;
use App\Models\Sale;
use App\Models\SupermarketApproval;
use App\Models\SupermarketDraftImport;
use App\Services\CompanyOperations;
use App\Services\Supermarket;
use App\Services\SupermarketApprovals;
use App\Services\SupermarketDrafts;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait SupermarketControlCases
{
    public function test_supermarket_supervisor_grant_is_scoped_consumed_once_and_preserves_discount_rules_at_checkout(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $this->product->update(['discount_enabled' => true, 'max_discount_percent' => '20.00']);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $market = app(Supermarket::class);
        $approvals = app(SupermarketApprovals::class);
        $this->openTestSupermarketShift($cashier->id);
        $cart = $market->start((string) Str::uuid(), $cashier->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '2', $cashier->id);
        $line = $cart->items[0]['line_key'];
        $input = ['request_key' => (string) Str::uuid(), 'version' => $cart->version,
            'action' => 'discount', 'line_key' => $line, 'discount_percent' => '10.00', 'reason' => 'Ajuste comercial autorizado'];
        $approval = $approvals->request($cart->id, $input, $cashier->id);
        $this->assertSame($approval->id, $approvals->request($cart->id, $input, $cashier->id)->id);
        $this->assertSame(1, SupermarketApproval::count());
        $this->assertSame('200.00', $cart->fresh()->total);
        try {
            $approvals->decide($approval->id, 'approve', 'Tentativa do próprio caixa', $cashier->id);
            $this->fail('O caixa aprovou uma solicitação sem supervisão.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
        $approvals->decide($approval->id, 'approve', 'Conferência do supervisor', $this->user->id);
        $cart = $approvals->apply($cart->id, $approval->id, $input['version'], $cashier->id);
        $this->assertSame('180.00', $cart->total);
        $this->assertSame('10.00', $cart->items[0]['discount_percent']);
        $this->assertSame('used', $approval->fresh()->status);
        $this->assertSame($cart->version, $approvals->apply($cart->id, $approval->id, $input['version'], $cashier->id)->version);
        try {
            $approvals->request($cart->id, array_replace($input, ['discount_percent' => '15.00']), $cashier->id);
            $this->fail('A solicitação foi reaproveitada com outro desconto.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval', $error->errors());
        }
        $sale = $market->checkout($cart->id, $cart->version, ['payment_method' => 'invoice', 'paid' => false,
            'due_date' => now()->addDay()->toDateString(), 'installments' => 1], $cashier->id);
        $this->assertSame('180.00', $sale->total);
        $this->assertSame('20.00', $sale->discount_total);
        $this->assertSame(1, Sale::count());
        $this->assertSame('180.00', (string) FinancialEntry::where('sale_id', $sale->id)->sum('amount'));

        $cart = $market->start((string) Str::uuid(), $cashier->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $cashier->id);
        $input = ['request_key' => (string) Str::uuid(), 'version' => $cart->version, 'action' => 'remove',
            'line_key' => $cart->items[0]['line_key'], 'reason' => 'Produto devolvido ao balcão'];
        $expired = $approvals->request($cart->id, $input, $cashier->id);
        $expired->update(['expires_at' => now()->subSecond()]);
        try {
            $approvals->decide($expired->id, 'approve', 'Pedido expirado', $this->user->id);
            $this->fail('Uma autorização expirada foi aprovada.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval', $error->errors());
        }
        $stale = $approvals->request($cart->id, array_replace($input, ['request_key' => (string) Str::uuid()]), $cashier->id);
        $approvals->decide($stale->id, 'approve', 'Remoção autorizada', $this->user->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $cashier->id);
        try {
            $approvals->apply($cart->id, $stale->id, $stale->cart_version, $cashier->id);
            $this->fail('Uma autorização de versão anterior foi aplicada.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval', $error->errors());
        }
        $this->assertSame('2.000', $cart->fresh()->items[0]['quantity']);
    }

    public function test_supermarket_approval_rechecks_supervisor_limit_and_handles_reduce_remove_and_cancel(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $this->product->update(['discount_enabled' => true, 'max_discount_percent' => '20.00']);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $market = app(Supermarket::class);
        $approvals = app(SupermarketApprovals::class);
        $this->openTestSupermarketShift($cashier->id);
        $cart = $market->start((string) Str::uuid(), $cashier->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '3', $cashier->id);
        foreach (['reduce', 'remove', 'cancel'] as $action) {
            $data = ['request_key' => (string) Str::uuid(), 'version' => $cart->version,
                'action' => $action, 'reason' => 'Alteração conferida pelo supervisor'];
            if ($action !== 'cancel') $data['line_key'] = $cart->items[0]['line_key'];
            if ($action === 'reduce') $data['quantity'] = '2.000';
            $grant = $approvals->request($cart->id, $data, $cashier->id);
            $approvals->decide($grant->id, 'approve', 'Itens conferidos', $this->user->id);
            $cart = $approvals->apply($cart->id, $grant->id, $cart->version, $cashier->id);
            $this->assertSame('used', $grant->fresh()->status);
            if ($action === 'reduce') $this->assertSame('2.000', $cart->items[0]['quantity']);
            if ($action === 'remove') $this->assertSame([], $cart->items);
            if ($action === 'cancel') $this->assertSame('cancelled', $cart->status);
        }
        $cart = $market->start((string) Str::uuid(), $cashier->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $cashier->id);
        $profiles = app(\App\Access\AccessProfiles::class);
        $profileId = $profiles->save(['version' => \App\Models\CompanyOperation::findOrFail(1)->version,
            'name' => 'Supervisor limitado', 'permissions' => ['supermarket.supervise'], 'max_discount_percent' => '5.00'], $this->user->id);
        $supervisor = \App\Models\User::create(['name' => 'Supervisor', 'email' => 'limited-supervisor@example.test',
            'password' => 'test-password-123', 'role' => 'operator']);
        \Illuminate\Support\Facades\DB::connection('tenant')->transaction(fn () => $profiles->assign($supervisor->id, 'operator', $profileId, $this->user->id));
        $data = ['request_key' => (string) Str::uuid(), 'version' => $cart->version, 'action' => 'discount',
            'line_key' => $cart->items[0]['line_key'], 'discount_percent' => '10.00', 'reason' => 'Pedido comercial do cliente'];
        $grant = $approvals->request($cart->id, $data, $cashier->id);
        try {
            $approvals->decide($grant->id, 'approve', 'Tentativa acima do perfil', $supervisor->id);
            $this->fail('O supervisor ultrapassou o limite do próprio perfil.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval', $error->errors());
        }
        $this->assertSame('pending', $grant->fresh()->status);
        $profiles->save(['version' => \App\Models\CompanyOperation::findOrFail(1)->version,
            'name' => 'Supervisor limitado', 'permissions' => ['supermarket.supervise'], 'max_discount_percent' => '15.00'], $this->user->id, $profileId);
        $approvals->decide($grant->id, 'approve', 'Desconto dentro do perfil', $supervisor->id);
        $supervisor->update(['active' => false]);
        try {
            $approvals->apply($cart->id, $grant->id, $cart->version, $cashier->id);
            $this->fail('A autorização de um supervisor inativo foi consumida.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('approval', $error->errors());
        }
        $this->assertSame('100.00', $cart->fresh()->total);
        $this->assertSame('approved', $grant->fresh()->status);
    }

    public function test_supermarket_offline_item_drafts_reconcile_online_once_without_creating_sales_or_payments(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $market = app(Supermarket::class);
        $drafts = app(SupermarketDrafts::class);
        $this->openTestSupermarketShift($cashier->id);
        $cart = $market->start((string) Str::uuid(), $cashier->id);
        $line = ['request_key' => (string) Str::uuid(), 'code' => $this->product->sku, 'quantity' => '2'];
        $input = ['request_key' => (string) Str::uuid(), 'version' => $cart->version, 'items' => [$line]];
        $preview = $drafts->preview($cart->id, $input, $cashier->id);
        $this->assertSame('200.00', $preview['items'][0]['total']);
        $this->assertSame([], $cart->fresh()->items);
        $result = $drafts->import($cart->id, $input + ['paid' => true, 'unit_price' => '0.01'], $cashier->id);
        $this->assertSame([$line['request_key']], $result['imported_request_keys']);
        $this->assertSame('2.000', $cart->fresh()->items[0]['quantity']);
        $this->assertSame('200.00', $cart->fresh()->total);
        $this->assertSame($result, $drafts->import($cart->id, $input, $cashier->id));
        $this->assertSame(1, SupermarketDraftImport::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, FinancialEntry::count());
        $changed = $input;
        $changed['items'][0]['quantity'] = '3';
        try {
            $drafts->import($cart->id, $changed, $cashier->id);
            $this->fail('Um rascunho foi repetido com quantidade diferente.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('items', $error->errors());
        }
        $freshVersion = $cart->fresh()->version;
        $bad = ['request_key' => (string) Str::uuid(), 'version' => $freshVersion, 'items' => [
            ['request_key' => (string) Str::uuid(), 'code' => $this->product->sku, 'quantity' => '1'],
            ['request_key' => (string) Str::uuid(), 'code' => '0000000000000', 'quantity' => '1'],
        ]];
        try {
            $drafts->import($cart->id, $bad, $cashier->id);
            $this->fail('Um rascunho parcialmente inválido foi aplicado.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('code', $error->errors());
        }
        $this->assertSame($freshVersion, $cart->fresh()->version);
        $this->assertSame('2.000', $cart->fresh()->items[0]['quantity']);
        $this->assertSame(1, SupermarketDraftImport::count());
        $this->assertSame('0.750', app(\App\Services\ManualWeightInput::class)->quantity('0.750'));
        try {
            app(\App\Services\ManualWeightInput::class)->quantity('0.7501');
            $this->fail('Peso manual com precisão inválida foi aceito.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('quantity', $error->errors());
        }
    }
}
