<?php

namespace Tests\Support;

use App\Access\AccessProfiles;
use App\Models\CompanyOperation;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\TenantConnection;
use Illuminate\Support\Facades\DB;

trait OperationalRoleCases
{
    private function operationalUserForPreset(string $preset): User
    {
        $profiles = app(AccessProfiles::class);
        $template = AccessProfiles::PRESETS[$preset];
        $profileId = $profiles->save($template + ['version' => CompanyOperation::findOrFail(1)->version], $this->user->id);
        $user = User::create(['name' => $template['name'], 'email' => $preset.'@example.test', 'password' => 'test-password-123', 'role' => 'operator']);
        DB::connection('tenant')->transaction(fn () => $profiles->assign($user->id, 'operator', $profileId, $this->user->id));
        return $user;
    }

    public function test_operational_role_templates_require_explicit_assignment_and_open_only_their_own_panel(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['commerce', 'restaurant', 'supermarket'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $version = CompanyOperation::findOrFail(1)->version;
        $state = app(AccessProfiles::class)->state(true);
        foreach (array_keys(AccessProfiles::PRESETS) as $preset) {
            $this->get('/app/a/access-profiles?preset='.$preset)->assertOk();
            app(TenantConnection::class)->connect($this->a);
            $this->assertSame($version, CompanyOperation::findOrFail(1)->version);
            $this->assertSame($state, app(AccessProfiles::class)->state(true));
        }
        $users = [];
        foreach (array_keys(AccessProfiles::PRESETS) as $preset) {
            $users[$preset] = $this->operationalUserForPreset($preset);
        }
        $expected = ['waiter' => '/app/a/restaurante', 'kitchen' => '/app/a/restaurante/producao',
            'restaurant_cashier' => '/app/a/restaurante', 'supermarket_cashier' => '/app/a/supermarket'];
        foreach ($expected as $preset => $destination) {
            $this->post('/app/a/logout')->assertRedirect();
            $this->post('/app/a/login', ['email' => strtoupper($users[$preset]->email), 'password' => 'test-password-123'])
                ->assertRedirect($destination)->assertSessionHasNoErrors();
            $screen = $this->get($destination)->assertOk();
            foreach (['products', 'catalog/customers', 'reports', 'users', 'access-profiles', 'setup', 'sales'] as $forbidden) {
                $screen->assertDontSee('href="http://localhost/app/a/'.$forbidden.'"', false);
                $this->get('/app/a/'.$forbidden)->assertForbidden();
            }
            $this->get('/app/a')->assertForbidden();
            $this->post('/app/a/restaurante/mesas', ['name' => 'Cadastro indevido'])->assertForbidden();
            if ($preset === 'waiter') {
                $this->get('/app/a/restaurante/producao')->assertForbidden();
                $this->post('/app/a/restaurante/comandas/999/fechar', ['version' => 1])->assertForbidden();
            }
            if ($preset === 'kitchen') {
                $this->get('/app/a/restaurante')->assertForbidden();
                $this->post('/app/a/restaurante/comandas', ['table_id' => 1])->assertForbidden();
                $this->post('/app/a/restaurante/comandas/999/itens', ['version' => 1])->assertForbidden();
            }
            if ($preset === 'supermarket_cashier') {
                $this->get('/app/a/restaurante')->assertForbidden();
            }
        }
        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(['operator'], array_keys(app(AccessProfiles::class)->state(true)['profiles']));
        $this->assertSame([], app(AccessProfiles::class)->state(true)['assignments']);
    }

    public function test_supermarket_cashier_finishes_and_prints_only_own_receipt_without_catalog_or_sales_access(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $this->post('/app/a/login', ['email' => $cashier->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/supermarket');
        $this->openTestSupermarketShift($cashier->id);
        $this->post('/app/a/supermarket/carts', ['request_key' => (string) \Illuminate\Support\Str::uuid()])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $cart = \App\Models\SupermarketCart::firstOrFail();
        $base = '/app/a/supermarket/carts/'.$cart->id;
        $this->post($base.'/items', ['version' => $cart->version, 'code' => $this->product->sku, 'quantity' => '1'])->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $cart->refresh();
        $line = $cart->items[0]['line_key'];
        $this->post($base.'/items/'.$line.'/remove', ['version' => $cart->version, 'reason' => 'Caixa sem autorização'])->assertForbidden();
        $payment = ['version' => $cart->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        $this->post($base.'/checkout', $payment)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->post($base.'/checkout', $payment)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->get($base)->assertOk()->assertSee('Imprimir comprovante')->assertDontSee('/app/a/sales/', false);
        $this->get($base.'/receipt')->assertOk()->assertSee('Documento não fiscal')->assertSee($this->product->name);
        app(TenantConnection::class)->connect($this->a);
        $sale = \App\Models\Sale::firstOrFail();
        $this->assertSame($cashier->id, $sale->user_id);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->get('/app/a/sales/'.$sale->id)->assertForbidden();
        $this->get('/app/a/products')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $another = User::create(['name' => 'Outro caixa', 'email' => 'other-cashier@example.test', 'password' => 'test-password-123', 'role' => 'operator']);
        $profile = app(AccessProfiles::class)->profileId($cashier->id);
        DB::connection('tenant')->transaction(fn () => app(AccessProfiles::class)->assign($another->id, 'operator', $profile, $this->user->id));
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $another->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/supermarket');
        $this->get($base)->assertNotFound();
        $this->get($base.'/receipt')->assertNotFound();
    }


    public function test_waiter_kitchen_and_restaurant_cashier_complete_a_table_without_management_permissions(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant',
        ]), $this->user->id);
        $waiter = $this->operationalUserForPreset('waiter');
        $kitchen = $this->operationalUserForPreset('kitchen');
        $cashier = $this->operationalUserForPreset('restaurant_cashier');
        $table = app(\App\Services\RestaurantOperations::class)->createTable(['name' => 'Mesa das equipes'], $this->user->id);
        $this->post('/app/a/login', ['email' => $waiter->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante');
        $this->post('/app/a/restaurante/comandas', ['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $order = \App\Models\RestaurantOrder::firstOrFail();
        $base = '/app/a/restaurante/comandas/'.$order->id;
        $this->post($base.'/itens', ['version' => $order->version, 'product_id' => $this->product->id,
            'quantity' => '1', 'station' => 'kitchen', 'request_key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $item = \App\Models\RestaurantOrderItem::firstOrFail();
        $this->post($base.'/enviar', ['version' => $order->fresh()->version])->assertRedirect()->assertSessionHasNoErrors();
        $this->post($base.'/fechar', ['version' => 1])->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->postJson($base.'/itens/'.$item->id.'/entregar', ['version' => $order->fresh()->version])->assertUnprocessable();
        $this->post($base.'/itens/'.$item->id.'/etapa', ['version' => 1, 'status' => 'preparing'])->assertForbidden();
        $this->post($base.'/itens/'.$item->id.'/cancelar', ['version' => 1, 'reason' => 'Sem autorização'])->assertForbidden();
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $kitchen->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante/producao');
        $this->get('/app/a/restaurante/producao')->assertOk()->assertSee($this->product->name);
        $this->get($base)->assertForbidden();
        foreach (['preparing', 'ready'] as $status) {
            app(TenantConnection::class)->connect($this->a);
            $this->post($base.'/itens/'.$item->id.'/etapa', ['version' => $order->fresh()->version, 'status' => $status])
                ->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $waiter->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante');
        app(TenantConnection::class)->connect($this->a);
        $this->post($base.'/itens/'.$item->id.'/entregar', ['version' => $order->fresh()->version, 'status' => 'preparing'])
            ->assertRedirect($base)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('delivered', $item->fresh()->status);
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $cashier->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante');
        app(TenantConnection::class)->connect($this->a);
        $payment = ['version' => $order->fresh()->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => now()->addDay()->toDateString()];
        $this->post($base.'/fechar', $payment)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->post($base.'/fechar', $payment)->assertRedirect($base)->assertSessionHasNoErrors();
        $this->get($base)->assertOk()->assertSee('Imprimir comprovante')->assertDontSee('/app/a/sales/', false);
        $this->get($base.'/comprovante')->assertOk()->assertSee('Documento não fiscal');
        $this->get('/app/a/sales')->assertForbidden();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, \App\Models\Sale::count());
        $this->assertSame($cashier->id, \App\Models\Sale::firstOrFail()->user_id);
        $profile = app(AccessProfiles::class)->profileId($cashier->id);
        DB::connection('tenant')->transaction(fn () => app(AccessProfiles::class)->assign($waiter->id, 'operator', $profile, $this->user->id));
        $this->post('/app/a/logout')->assertRedirect();
        $this->post('/app/a/login', ['email' => $waiter->email, 'password' => 'test-password-123'])->assertRedirect('/app/a/restaurante');
        $this->get($base.'/comprovante')->assertNotFound();
    }

}
