<?php

namespace Tests\Support;

use App\Models\CompanyOperation;
use App\Services\CompanyOperations;
use App\Services\TenantConnection;
use Illuminate\Validation\ValidationException;

trait OperationalExpansionCases
{
    public function test_restaurant_transfer_checks_occupancy_and_serializes_two_orders_for_one_target_table(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $service = app(\App\Services\RestaurantOperations::class);
        $tables = [];
        foreach (['Mesa A', 'Mesa B', 'Mesa destino'] as $name) $tables[] = $service->createTable(['name' => $name], $this->user->id);
        $orders = [];
        foreach (array_slice($tables, 0, 2) as $table) {
            $orders[] = $service->open(['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()], $this->user->id)->fresh();
        }
        $first = ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'version' => $orders[0]->version,
            'table_id' => $tables[1]->id, 'reason' => 'Cliente pediu a troca'];
        try {
            $service->transfer($orders[0]->id, $first, $this->user->id);
            $this->fail('Mesa ocupada recebeu outra comanda.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('table_id', $exception->errors());
        }
        $requests = [];
        foreach ($orders as $order) $requests[] = ['request_key' => (string) \Illuminate\Support\Str::uuid(), 'version' => $order->version,
            'table_id' => $tables[2]->id, 'reason' => 'Cliente pediu a troca'];
        $transfer = function ($order, array $input) use ($service) {
            try { return $service->transfer($order->id, $input, $this->user->id)->id; }
            catch (ValidationException $exception) {
                $this->assertArrayHasKey('table_id', $exception->errors());
                return 0;
            }
        };
        $ids = $this->race(null, fn () => $transfer($orders[0], $requests[0]), fn () => $transfer($orders[1], $requests[1]));
        $this->assertCount(1, array_filter($ids));
        $this->assertSame(1, \App\Models\RestaurantOrder::where('restaurant_table_id', $tables[2]->id)->where('status', 'open')->count());
        $winner = $ids[0] ? 0 : 1;
        $result = $service->transfer($orders[$winner]->id, $requests[$winner], $this->user->id);
        $this->assertSame($tables[2]->id, $result->restaurant_table_id);
        $this->assertSame(1, \App\Models\OperationRecord::where('action', 'restaurant_transfer')->count());
        try {
            $service->transfer($orders[$winner]->id, array_replace($requests[$winner], ['reason' => 'Tentativa modificada']), $this->user->id);
            $this->fail('Repetição alterada foi aceita.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('request_key', $exception->errors());
        }
        $this->assertSame(0, \App\Models\Sale::count());
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    public function test_new_fronts_split_payments_only_at_checkout_and_preserve_exact_balance_on_retry(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['restaurant', 'supermarket'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $restaurant = app(\App\Services\RestaurantOperations::class);
        $table = $restaurant->createTable(['name' => 'Mesa pagamento dividido'], $this->user->id);
        $order = $restaurant->open(['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()], $this->user->id)->fresh();
        $item = $restaurant->addItem($order->id, ['version' => $order->version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => '1', 'station' => 'kitchen'], $this->user->id);
        $restaurant->send($order->id, ['version' => $order->fresh()->version], $this->user->id);
        foreach (['preparing', 'ready'] as $status) $restaurant->transitionItem($order->id, $item->id,
            ['version' => $order->fresh()->version, 'status' => $status], $this->user->id);
        $restaurant->deliverItem($order->id, $item->id, ['version' => $order->fresh()->version], $this->user->id);
        $market = app(\App\Services\Supermarket::class);
        $cart = $market->start((string) \Illuminate\Support\Str::uuid(), $this->user->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $this->user->id);
        $this->assertSame(0, \App\Models\FinancialEntry::count());
        $this->assertSame(0, \App\Models\Payment::count());
        $payment = ['payments' => [
            ['payment_option' => 'cash', 'amount' => '30.00', 'paid' => 1, 'cash_received' => '50.00'],
            ['payment_option' => 'invoice', 'amount' => '70.00', 'paid' => 0, 'due_date' => now()->addDay()->toDateString(), 'installments' => 2],
        ]];
        $paths = ['/app/a/restaurante/comandas/'.$order->id.'/fechar' => $order->fresh()->version,
            '/app/a/supermarket/carts/'.$cart->id.'/checkout' => $cart->version];
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        foreach ($paths as $path => $version) {
            $payload = $payment + ['version' => $version];
            $this->post($path, $payload)->assertRedirect()->assertSessionHasNoErrors();
            $this->post($path, $payload)->assertRedirect()->assertSessionHasNoErrors();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, \App\Models\Sale::count());
        $this->assertSame(6, \App\Models\FinancialEntry::count());
        $this->assertSame('60.00', number_format(\App\Models\FinancialEntry::sum('paid_amount'), 2, '.', ''));
        $this->assertSame('140.00', number_format(\App\Models\FinancialEntry::sum('amount') - \App\Models\FinancialEntry::sum('paid_amount'), 2, '.', ''));
        foreach (\App\Models\Sale::get() as $sale) {
            $this->assertFalse($sale->paid);
            $rows = app(\App\Services\SalePayments::class)->rows($sale->id);
            $this->assertSame('20.00', $rows[0]['change']);
        }
        $this->assertSame(0, \App\Models\StockMovement::count());
    }

    private function weightedLabel(string $plu, string $value): string
    {
        $body = '20'.str_pad($plu, 5, '0', STR_PAD_LEFT).str_pad($value, 5, '0', STR_PAD_LEFT);
        return $body.\App\Services\WeightedLabels::checkDigit($body);
    }

    public function test_weighted_ean_requires_company_configuration_valid_checksum_and_unambiguous_kilogram_product(): void
    {
        $operations = app(CompanyOperations::class);
        $settings = $operations->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $this->product->update(['sku' => '12', 'unit' => 'KG', 'sale_price' => '20.00']);
        $code = $this->weightedLabel('12', '500');
        $this->assertFalse($operations->current()['weighed_labels']['enabled']);
        $service = app(\App\Services\Supermarket::class);
        try { $service->scan($code); $this->fail('Etiqueta pesável foi interpretada sem habilitação.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('code', $exception->errors()); }
        $operations->save($this->operationPayload(['version' => $settings->fresh()->version, 'inventory' => false,
            'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
            'weighed_labels' => array_replace(\App\Services\WeightedLabels::DEFAULTS, ['enabled' => true])]), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->getJson('/app/a/supermarket/lookup?code='.$code)->assertOk()->assertJsonPath('scanned_quantity', '0.500');
        app(TenantConnection::class)->connect($this->a);
        $cart = $service->start((string) \Illuminate\Support\Str::uuid(), $this->user->id);
        $this->post('/app/a/supermarket/carts/'.$cart->id.'/items', ['version' => $cart->version, 'code' => $code, 'quantity' => '1'])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('10.00', $cart->refresh()->total);
        $this->assertSame('0.500', $cart->items[0]['quantity']);
        $badChecksum = substr($code, 0, 12).(((int)$code[12] + 1) % 10);
        foreach ([$badChecksum, $this->weightedLabel('12', '0')] as $invalid) {
            $this->postJson('/app/a/supermarket/carts/'.$cart->id.'/items', ['version' => $cart->version, 'code' => $invalid, 'quantity' => '1'])->assertUnprocessable();
            app(TenantConnection::class)->connect($this->a);
            $this->assertSame('10.00', $cart->fresh()->total);
            $this->assertCount(1, $cart->fresh()->items);
        }
        $this->postJson('/app/a/supermarket/carts/'.$cart->id.'/items', ['version' => $cart->version, 'code' => $code, 'quantity' => '2'])->assertUnprocessable();
        app(TenantConnection::class)->connect($this->a);
        $this->product->update(['unit' => 'UN']);
        try { $service->scan($code); $this->fail('Produto por unidade recebeu etiqueta em peso.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('code', $exception->errors()); }
        $this->product->update(['unit' => 'KG']);
        \App\Models\Product::create(['name' => 'PLU duplicado', 'sku' => '00012', 'unit' => 'KG',
            'product_group_id' => $this->product->product_group_id, 'sale_price' => '20.00']);
        try { $service->scan($code); $this->fail('PLU ambíguo foi interpretado.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('code', $exception->errors()); }
        app(TenantConnection::class)->connect($this->b);
        $this->assertFalse($operations->current()['weighed_labels']['enabled']);
        $this->assertSame(0, \App\Models\SupermarketCart::count());
    }

    public function test_price_encoded_labels_require_exact_representable_quantity_and_do_not_reprice_existing_line(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
            'weighed_labels' => array_replace(\App\Services\WeightedLabels::DEFAULTS, ['enabled' => true, 'content' => 'price', 'decimals' => 2]),
        ]), $this->user->id);
        $this->product->update(['sku' => '12', 'unit' => 'KG', 'sale_price' => '20.00']);
        $service = app(\App\Services\Supermarket::class);
        $label = $this->weightedLabel('12', '1000');
        $cart = $service->start((string) \Illuminate\Support\Str::uuid(), $this->user->id);
        $cart = $service->add($cart->id, $cart->version, $label, '1', $this->user->id);
        $this->assertSame('0.500', $cart->items[0]['quantity']);
        $this->assertSame('10.00', $cart->total);
        $this->product->update(['sale_price' => '999.99']);
        try { $service->add($cart->id, $cart->version, $this->weightedLabel('12', '1'), '1', $this->user->id); $this->fail('Valor impossível na precisão atual foi aceito.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('code', $exception->errors()); }
        $this->assertSame('10.00', $cart->fresh()->total);
        $this->assertCount(1, $cart->fresh()->items);
        $this->assertSame('20.00', $cart->fresh()->items[0]['unit_price']);
        $this->assertSame(0, \App\Models\Sale::count());
    }


    public function test_restaurant_prebill_and_production_tickets_are_read_only_scoped_and_follow_operational_profiles(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $waiter = $this->operationalUserForPreset('waiter');
        $kitchen = $this->operationalUserForPreset('kitchen');
        $customer = \App\Models\Customer::create(['name' => 'Cliente da pré-conta', 'phone' => '(35) 99999-0101']);
        $service = app(\App\Services\RestaurantOperations::class);
        $table = $service->createTable(['name' => 'Mesa impressão'], $this->user->id);
        $order = $service->open(['table_id' => $table->id, 'customer_id' => $customer->id,
            'request_key' => (string) \Illuminate\Support\Str::uuid()], $waiter->id)->fresh();
        foreach (['kitchen' => 'Sem sal', 'bar' => 'Sem gelo'] as $station => $notes) {
            $service->addItem($order->id, ['version' => $order->fresh()->version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
                'product_id' => $this->product->id, 'quantity' => '1', 'station' => $station, 'notes' => $notes], $waiter->id);
        }
        $service->send($order->id, ['version' => $order->fresh()->version], $waiter->id);
        $batch = \App\Models\OperationRecord::where('action', 'restaurant_send')->firstOrFail();
        $before = [$order->fresh()->version, \App\Models\OperationRecord::count(), \App\Models\Sale::count(), \App\Models\FinancialEntry::count(), \App\Models\StockMovement::count()];
        $base = '/app/a/restaurante/comandas/'.$order->id;
        $this->post('/app/a/login', ['email' => $waiter->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante');
        $this->get($base.'/pre-conta')->assertOk()->assertSee('Pré-conta')->assertSee('Documento não fiscal')->assertSee('200,00')->assertSee($customer->phone);
        $this->get($base.'/ticket?batch='.$batch->id.'&station=kitchen')->assertOk()->assertSee('Sem sal')->assertDontSee('Sem gelo')->assertDontSee('R$')->assertSee($customer->phone);
        $this->get($base.'/ticket?batch='.$batch->id.'&station=bar')->assertOk()->assertSee('Sem gelo')->assertDontSee('Sem sal');
        $this->get($base.'/ticket?batch=999999')->assertNotFound();
        $this->getJson($base.'/ticket?station=forged')->assertUnprocessable();
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $kitchen->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante/producao');
        $this->get($base.'/ticket?batch='.$batch->id)->assertOk()->assertSee('Sem sal')->assertSee('Sem gelo');
        $this->get($base.'/pre-conta')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($before, [$order->fresh()->version, \App\Models\OperationRecord::count(), \App\Models\Sale::count(), \App\Models\FinancialEntry::count(), \App\Models\StockMovement::count()]);
        app(TenantConnection::class)->connect($this->b);
        $other = \App\Models\User::create(['name' => 'Outra loja', 'email' => 'other-print@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        app(CompanyOperations::class)->save($this->operationPayload([
            'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant',
        ]), $other->id);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->get('/app/b/restaurante/comandas/'.$order->id.'/pre-conta')->assertNotFound();
        $this->get('/app/b/restaurante/comandas/'.$order->id.'/ticket?batch='.$batch->id)->assertNotFound();
    }

}
