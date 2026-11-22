<?php

namespace Tests\Support;

use App\Models\CompanyOperation;
use App\Services\CompanyOperations;
use App\Services\OperationalModes;
use App\Services\TenantConnection;
use Illuminate\Validation\ValidationException;

trait OperationalSegmentCases
{
    public function test_operational_modes_preserve_legacy_and_validate_wizard_configuration_per_company(): void
    {
        $operations = app(CompanyOperations::class);
        $modes = app(OperationalModes::class);
        $this->assertSame('commerce', $operations->current()['segment']);
        $this->assertSame(['commerce'], $operations->current()['operation_modes']);
        $this->assertSame('commerce', $operations->current()['default_operation']);
        $this->assertTrue($modes->enabled('commerce'));
        $this->assertFalse($modes->enabled('restaurant'));

        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $payload = $this->operationPayload([
            'segment' => 'restaurant',
            'operation_modes' => ['restaurant', 'commerce'],
            'default_operation' => 'restaurant',
        ]);
        $this->post('/app/a/setup', $payload)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $saved = CompanyOperation::findOrFail(1);
        $this->assertSame('restaurant', $this->a->fresh()->operation_segment);
        $configurationVersion = \Illuminate\Support\Facades\DB::connection('tenant')->table('company_operation_audits')
            ->where('action', 'configuration')->max('version');
        $this->assertEquals($configurationVersion, $this->a->fresh()->operation_segment_version);
        $this->assertNull($this->b->fresh()->operation_segment);
        $this->assertSame('restaurant', $operations->current()['segment']);
        $this->assertEqualsCanonicalizing(['restaurant', 'commerce'], $operations->current()['operation_modes']);
        $this->assertSame('restaurant', $operations->current()['default_operation']);
        $this->get('/app/a/setup?step=2')->assertOk()->assertSee('Restaurante');

        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(['commerce'], $operations->current()['operation_modes']);
        app(TenantConnection::class)->connect($this->a);
        foreach ([['operation_modes' => []], ['default_operation' => 'supermarket'], ['segment' => 'forged'], ['operation_modes' => ['forged']]] as $invalid) {
            $version = $saved->fresh()->version;
            try {
                $operations->save(array_replace($payload, ['version' => $version], $invalid), $this->user->id);
                $this->fail('Configuração operacional inválida foi aceita.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $this->assertSame($version, $saved->fresh()->version);
        }
    }

    public function test_backfill_projects_each_tenants_saved_segment_and_preserves_unassigned_and_newer_data(): void
    {
        $connection = app(TenantConnection::class);
        $legacy = CompanyOperation::updateOrCreate(['id' => 1], ['configuration' => ['segment' => 'supermarket'], 'version' => 5]);
        $connection->connect($this->b);
        CompanyOperation::updateOrCreate(['id' => 1], ['configuration' => ['segment' => 'legado-desconhecido'], 'version' => 11]);

        $this->artisan('platform:sync-company-segments', ['--company' => $this->a->slug])->assertSuccessful();
        $this->assertSame('supermarket', $this->a->fresh()->operation_segment);
        $this->assertNull($this->b->fresh()->operation_segment_version);

        $this->artisan('platform:sync-company-segments')->assertSuccessful();
        $this->assertNull($this->b->fresh()->operation_segment);
        $this->assertEquals(11, $this->b->fresh()->operation_segment_version);
        $this->assertNull(config('tenancy.resolved_tenant_id'));
        $captured = $this->a->fresh()->getRawOriginal();
        $this->artisan('platform:sync-company-segments')->assertSuccessful();
        $this->assertSame($captured, $this->a->fresh()->getRawOriginal());

        // A delayed event for revision 4 cannot replace the projection of revision 5.
        $legacy->version = 4;
        $legacy->configuration = ['segment' => 'restaurant'];
        $connection->connect($this->a);
        app(\App\Platform\CompanySegment::class)->sync($this->a->id, $legacy);
        $this->assertSame($captured, $this->a->fresh()->getRawOriginal());
        $this->artisan('platform:sync-company-segments', ['--company' => 'inexistente'])->assertFailed();
    }

    public function test_restaurant_http_flow_closes_once_and_keeps_inventory_unchanged_until_checkout(): void
    {
        $operations = app(CompanyOperations::class);
        $operations->save($this->operationPayload([
            'segment' => 'restaurant', 'operation_modes' => ['commerce', 'restaurant'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $this->move('in', '10', '50');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante')->assertOk();
        $this->post('/app/a/restaurante/mesas', ['name' => 'Mesa auditoria'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $table = \App\Models\RestaurantTable::firstOrFail();
        $open = ['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->post('/app/a/restaurante/comandas', $open)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $order = \App\Models\RestaurantOrder::firstOrFail();
        $path = '/app/a/restaurante/comandas/'.$order->id;
        $this->post('/app/a/restaurante/comandas', $open)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/app/a/restaurante/comandas', array_replace($open, ['request_key' => (string) \Illuminate\Support\Str::uuid()]))
            ->assertSessionHasErrors('table_id');
        app(TenantConnection::class)->connect($this->a);
        $version = $order->fresh()->version;
        $this->post($path.'/itens', ['version' => $version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => '2.000', 'station' => 'kitchen', 'notes' => 'Sem sal'])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $item = \App\Models\RestaurantOrderItem::firstOrFail();
        $this->assertSame('10.000', $this->product->fresh()->quantity);
        $this->assertSame(0, \App\Models\Sale::count());
        $this->post($path.'/enviar', ['version' => $order->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        foreach (['preparing', 'ready', 'delivered'] as $status) {
            app(TenantConnection::class)->connect($this->a);
            $this->post($path.'/itens/'.$item->id.($status === 'delivered' ? '/entregar' : '/etapa'), ['version' => $order->fresh()->version, 'status' => $status])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('10.000', $this->product->fresh()->quantity);
        $payment = ['version' => $order->fresh()->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        $this->post($path.'/fechar', $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path.'/fechar', $payment)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->assertSame('200.00', \App\Models\Sale::firstOrFail()->total);
        $this->assertSame('8.000', $this->product->fresh()->quantity);
        $this->assertSame('closed', $order->fresh()->status);
        $this->assertSame(1, \App\Models\FinancialEntry::where('direction', 'in')->count());

        app(TenantConnection::class)->connect($this->b);
        $other = \App\Models\User::create(['name' => 'Outra loja', 'email' => 'other@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $operations->save($this->operationPayload(['segment' => 'restaurant', 'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant']), $other->id);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->get('/app/b/restaurante/comandas/'.$order->id)->assertNotFound();
    }


    public function test_supermarket_scans_fractional_items_suspends_and_closes_once_with_operator_isolation(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'segment' => 'supermarket', 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $this->product->update(['barcode' => '7891234567890', 'unit' => 'KG']);
        $this->move('in', '10', '50');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->getJson('/app/a/supermarket/lookup?code=7891234567890')->assertOk()->assertJsonPath('id', $this->product->id);
        $request = ['request_key' => (string) \Illuminate\Support\Str::uuid()];
        $this->openTestSupermarketShift($this->user->id);
        $this->post('/app/a/supermarket/carts', $request)->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/app/a/supermarket/carts', $request)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $cart = \App\Models\SupermarketCart::firstOrFail();
        $path = '/app/a/supermarket/carts/'.$cart->id;
        $add = ['version' => $cart->version, 'code' => '7891234567890', 'quantity' => '0.250', 'unit_price' => '0.01'];
        $this->post($path.'/items', $add)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('25.00', $cart->fresh()->total);
        $this->assertSame('10.000', $this->product->fresh()->quantity);
        $this->assertSame(0, \App\Models\Sale::count());
        $this->post($path.'/items', $add)->assertSessionHasErrors('version');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('0.250', $cart->fresh()->items[0]['quantity']);
        $this->post($path.'/suspend', ['version' => $cart->refresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('suspended', $cart->refresh()->status);
        $second = \App\Models\User::create(['name' => 'Segundo caixa', 'email' => 'second@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $second->id, 'auth_version' => 0]);
        $this->get($path)->assertNotFound();
        $this->post($path.'/resume', ['version' => $cart->version])->assertNotFound();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->post($path.'/resume', ['version' => $cart->version])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $payment = ['version' => $cart->fresh()->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        $this->post($path.'/checkout', $payment)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($path.'/checkout', $payment)->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->assertSame('25.00', \App\Models\Sale::firstOrFail()->total);
        $this->assertSame('9.750', $this->product->fresh()->quantity);
        $this->assertSame('completed', $cart->fresh()->status);
        $this->assertSame(1, \App\Models\FinancialEntry::where('direction', 'in')->count());
    }


    public function test_segment_routes_require_enabled_modes_and_explicit_operator_permissions(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante')->assertForbidden();
        $this->get('/app/a/supermarket')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        app(CompanyOperations::class)->save($this->operationPayload([
            'operation_modes' => ['commerce', 'restaurant', 'supermarket'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $operator = \App\Models\User::create(['name' => 'Operador legado', 'email' => 'operator-segment@example.test', 'password' => 'test-password-123', 'role' => 'operator']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $operator->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante')->assertForbidden();
        $this->get('/app/a/supermarket')->assertForbidden();
        $this->post('/app/a/restaurante/mesas', ['name' => 'Tentativa sem perfil'])->assertForbidden();
        $this->post('/app/a/supermarket/carts', ['request_key' => (string) \Illuminate\Support\Str::uuid()])->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(0, \App\Models\RestaurantTable::count());
        $this->assertSame(0, \App\Models\SupermarketCart::count());
        $profiles = app(\App\Access\AccessProfiles::class);
        $profileId = $profiles->save(['version' => CompanyOperation::findOrFail(1)->version,
            'name' => 'Cozinha sem caixa', 'permissions' => ['restaurant.kitchen']], $this->user->id);
        \Illuminate\Support\Facades\DB::connection('tenant')->transaction(fn () => $profiles->assign($operator->id, 'operator', $profileId, $this->user->id));
        $this->get('/app/a/restaurante/producao')->assertOk();
        $this->post('/app/a/restaurante/comandas/999/fechar', ['version' => 1])->assertForbidden();
        $this->post('/app/a/supermarket/carts/999/checkout', ['version' => 1])->assertForbidden();
    }

    public function test_supermarket_checkout_retries_are_serialized_and_open_carts_block_disabling_mode(): void
    {
        $operations = app(CompanyOperations::class);
        $settings = $operations->save($this->operationPayload([
            'operation_modes' => ['commerce', 'supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $this->move('in', '10', '50');
        $service = app(\App\Services\Supermarket::class);
        $this->openTestSupermarketShift($this->user->id);
        $cart = $service->start((string) \Illuminate\Support\Str::uuid(), $this->user->id);
        $cart = $service->add($cart->id, $cart->version, $this->product->sku, '1', $this->user->id);
        try {
            $operations->save($this->operationPayload(['version' => $settings->fresh()->version]), $this->user->id);
            $this->fail('Carrinho aberto permitiu desabilitar a operação.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('operation_modes', $exception->errors());
        }
        $payment = ['payment_method' => 'invoice', 'paid' => false, 'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        $ids = $this->race(null, fn () => $service->checkout($cart->id, $cart->version, $payment, $this->user->id)->id);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->assertSame(1, \App\Models\FinancialEntry::where('direction', 'in')->count());
        $this->assertSame('9.000', $this->product->fresh()->quantity);
    }


    public function test_restaurant_open_and_checkout_concurrency_preserve_one_order_and_rollback_insufficient_stock(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'operation_modes' => ['commerce', 'restaurant'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $this->move('in', '1', '50');
        $service = app(\App\Services\RestaurantOperations::class);
        $table = $service->createTable(['name' => 'Mesa concorrente'], $this->user->id);
        $first = ['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()];
        $second = array_replace($first, ['request_key' => (string) \Illuminate\Support\Str::uuid()]);
        $open = function (array $input) use ($service) {
            try {
                return $service->open($input, $this->user->id)->id;
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('table_id', $exception->errors());
                return 0;
            }
        };
        $ids = $this->race(null, fn () => $open($first), fn () => $open($second));
        $this->assertCount(1, array_filter($ids));
        $this->assertSame(1, \App\Models\RestaurantOrder::count());
        $order = \App\Models\RestaurantOrder::firstOrFail();
        $item = $service->addItem($order->id, ['version' => $order->version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => '2', 'station' => 'kitchen'], $this->user->id);
        $service->send($order->id, ['version' => $order->fresh()->version], $this->user->id);
        foreach (['preparing', 'ready', 'delivered'] as $status) {
            if ($status === 'delivered') $service->deliverItem($order->id, $item->id, ['version' => $order->fresh()->version], $this->user->id);
            else $service->transitionItem($order->id, $item->id, ['version' => $order->fresh()->version, 'status' => $status], $this->user->id);
        }
        $payment = ['version' => $order->fresh()->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        try {
            $service->close($order->id, $payment, $this->user->id);
            $this->fail('Estoque insuficiente permitiu fechar comanda.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
        $this->assertSame('open', $order->fresh()->status);
        $this->assertSame(0, \App\Models\Sale::count());
        $this->assertSame(0, \App\Models\FinancialEntry::count());
        $this->assertSame('1.000', $this->product->fresh()->quantity);
        $this->move('in', '1', '50');
        $sales = $this->race(null, fn () => $service->close($order->id, $payment, $this->user->id)->id);
        $this->assertSame($sales[0], $sales[1]);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->assertSame(1, \App\Models\FinancialEntry::count());
        $this->assertSame('0.000', $this->product->fresh()->quantity);
    }


    public function test_supermarket_accepts_two_hundred_distinct_products_and_rejects_the_next_before_checkout(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['commerce', 'supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $service = app(\App\Services\Supermarket::class);
        $this->openTestSupermarketShift($this->user->id);
        $cart = $service->start((string) \Illuminate\Support\Str::uuid(), $this->user->id);
        for ($index = 1; $index <= 201; $index++) {
            $product = \App\Models\Product::create(['name' => 'Produto '.$index, 'sku' => 'SEG-'.$index,
                'product_group_id' => $this->product->product_group_id, 'sale_price' => '1.00', 'unit' => 'UN']);
            if ($index <= 200) {
                $cart = $service->add($cart->id, $cart->version, $product->sku, '1', $this->user->id);
            }
        }
        $this->assertCount(200, $cart->items);
        try {
            $service->add($cart->id, $cart->version, 'SEG-201', '1', $this->user->id);
            $this->fail('Limite de itens não foi aplicado antes de cobrar.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertCount(200, $cart->fresh()->items);
        $sale = $service->checkout($cart->id, $cart->version, ['payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()], $this->user->id);
        $this->assertSame('200.00', $sale->total);
        $this->assertSame(200, $sale->items()->count());
        $this->assertSame(0, \App\Models\StockMovement::count());
    }


    public function test_segment_operations_honor_tenant_dba_grants_and_license_sales_restrictions(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['commerce', 'restaurant', 'supermarket'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $dba = \App\Models\User::create(['name' => 'DBA da loja', 'email' => 'dba-segment@example.test', 'password' => 'test-password-123', 'role' => \App\Models\User::ROLE_INSTALLER]);
        $admin = $this->licensingAdmin();
        $grant = \App\Platform\InstallerInvitation::create(['tenant_id' => $this->a->id, 'issued_by' => $admin->id,
            'user_id' => $dba->id, 'name' => $dba->name, 'email' => $dba->email,
            'status' => 'accepted', 'delivery_status' => 'sent', 'access_profile' => 'tenant_dba', 'accepted_at' => now()]);
        $seller = \App\Models\Seller::create(['name' => 'Vendedor creditado', 'user_id' => $this->user->id, 'active' => true]);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $dba->id, 'auth_version' => 0]);
        $this->get('/app/a/supermarket')->assertOk();
        app(TenantConnection::class)->connect($this->a);
        $market = app(\App\Services\Supermarket::class);
        $this->openTestSupermarketShift($dba->id);
        $cart = $market->start((string) \Illuminate\Support\Str::uuid(), $dba->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $dba->id);
        $payment = ['payment_method' => 'invoice', 'paid' => false, 'installments' => 1,
            'due_date' => now()->addDay()->toDateString(), 'salesperson_id' => $seller->id];
        $this->post('/app/a/supermarket/carts/'.$cart->id.'/checkout', $payment + ['version' => $cart->version])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $restaurant = app(\App\Services\RestaurantOperations::class);
        $table = $restaurant->createTable(['name' => 'Mesa DBA'], $dba->id);
        $order = $restaurant->open(['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()], $dba->id)->fresh();
        $item = $restaurant->addItem($order->id, ['version' => $order->version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => '1', 'station' => 'bar'], $dba->id);
        $restaurant->send($order->id, ['version' => $order->fresh()->version], $dba->id);
        foreach (['preparing', 'ready', 'delivered'] as $status) {
            if ($status === 'delivered') $restaurant->deliverItem($order->id, $item->id, ['version' => $order->fresh()->version], $dba->id);
            else $restaurant->transitionItem($order->id, $item->id, ['version' => $order->fresh()->version, 'status' => $status], $dba->id);
        }
        $this->post('/app/a/restaurante/comandas/'.$order->id.'/fechar', $payment + ['version' => $order->fresh()->version])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, \App\Models\Sale::where('salesperson_id', $seller->id)->count());
        $this->assertSame(\App\Models\User::ROLE_INSTALLER, $dba->fresh()->getRawOriginal('role'));
        $contract = \App\Platform\Contract::create(['tenant_id' => $this->a->id, 'start_date' => today()->subMonth(),
            'end_date' => today()->addYear(), 'monthly_amount' => '100.00', 'due_day' => 1, 'restricted_modules' => ['sales']]);
        $installment = $contract->installments()->create(['tenant_id' => $this->a->id, 'period' => today()->startOfMonth(),
            'due_date' => today()->subDay(), 'amount' => '100.00']);
        $this->get('/app/a/restaurante')->assertStatus(402);
        $this->get('/app/a/supermarket')->assertStatus(402);
        $this->post('/app/a/restaurante/mesas', ['name' => 'Bloqueada'])->assertStatus(402);
        $this->post('/app/a/supermarket/carts', ['request_key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(402);
        $installment->update(['paid_amount' => '100.00']);
        $this->get('/app/a/supermarket')->assertOk();
        $grant->update(['status' => 'revoked', 'revoked_at' => now()]);
        $this->get('/app/a/supermarket')->assertRedirect('/app/a/login');
    }

}
