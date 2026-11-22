<?php

namespace Tests\Support;

use App\Models\CashClosure;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SupermarketCart;
use App\Models\SupermarketShift;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\Supermarket;
use App\Services\SupermarketShifts;
use App\Services\TenantConnection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait SupermarketShiftCases
{
    private function enableSupermarketForShiftTest(int $adminId): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'segment' => 'supermarket',
            'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
        ]), $adminId);
    }

    private function openTestSupermarketShift(int $userId, int $terminal = 1, string $opening = '0.00'): SupermarketShift
    {
        app(TenantConnection::class)->connect($this->a);

        return app(SupermarketShifts::class)->open([
            'request_key' => (string) Str::uuid(), 'terminal_number' => $terminal, 'opening_balance' => $opening,
        ], $userId);
    }

    public function test_supermarket_shift_routes_enforce_opening_ownership_and_allow_multiple_terminals(): void
    {
        $this->enableSupermarketForShiftTest($this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $other = $this->user;
        $url = '/app/a/supermarket';
        $opening = ['terminal_number' => 3, 'opening_balance' => '55.00', 'request_key' => (string) Str::uuid()];
        $this->post('/app/a/login', ['email' => $cashier->email, 'password' => 'test-password-123'])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Abrir caixa');
        $this->postJson($url.'/carts', ['request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->post($url.'/shifts', $opening)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->post($url.'/shifts', $opening)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->postJson($url.'/shifts', array_replace($opening, ['opening_balance' => '54.00']))
            ->assertUnprocessable()->assertJsonValidationErrors('request_key');
        $this->postJson($url.'/shifts', array_replace($opening, ['request_key' => (string) Str::uuid(), 'terminal_number' => 4]))
            ->assertUnprocessable()->assertJsonValidationErrors('shift');
        app(TenantConnection::class)->connect($this->a);
        $first = SupermarketShift::sole();
        $closing = ['counted_balance' => '55.00', 'reason' => 'end', 'request_key' => (string) Str::uuid()];
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->postJson($url.'/shifts/'.$first->id.'/close', $closing)->assertNotFound();
        $this->postJson($url.'/shifts', array_replace($opening, ['request_key' => (string) Str::uuid()]))
            ->assertUnprocessable()->assertJsonValidationErrors('terminal_number');
        $this->post($url.'/shifts', array_replace($opening, ['request_key' => (string) Str::uuid(), 'terminal_number' => 4]))
            ->assertRedirect($url)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, SupermarketShift::whereNull('closed_at')->count());
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $cashier->id, 'auth_version' => 0]);
        $this->post($url.'/shifts/'.$first->id.'/close', $closing)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->post($url.'/shifts/'.$first->id.'/close', $closing)->assertRedirect($url)->assertSessionHasNoErrors();
        $this->postJson($url.'/shifts/'.$first->id.'/close', array_replace($closing, ['counted_balance' => '53.00']))
            ->assertUnprocessable();
        $this->get($url)->assertOk()->assertSee('Abrir caixa');
        $this->postJson($url.'/carts', ['request_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('shift');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('0.00', $first->fresh()->difference);
        $this->assertSame($other->id, SupermarketShift::whereNull('closed_at')->sole()->user_id);
        $this->assertSame(0, CashClosure::count());
    }

    public function test_supermarket_shift_counts_only_payments_at_the_terminal_and_reassigns_suspended_cart_after_lunch(): void
    {
        $this->enableSupermarketForShiftTest($this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $shifts = app(SupermarketShifts::class);
        $market = app(Supermarket::class);
        $morning = $this->openTestSupermarketShift($cashier->id, 2, '50.00');
        $parallel = $this->openTestSupermarketShift($this->user->id, 5, '10.00');

        $other = $market->start((string) Str::uuid(), $this->user->id);
        $other = $market->add($other->id, $other->version, $this->product->sku, '1', $this->user->id);
        $market->checkout($other->id, $other->version, ['payment_method' => 'cash', 'paid' => true], $this->user->id);

        $first = $market->start((string) Str::uuid(), $cashier->id);
        $first = $market->add($first->id, $first->version, $this->product->sku, '1', $cashier->id);
        $payment = ['payments' => [
            ['payment_option' => 'cash', 'amount' => '30.00', 'cash_received' => '50.00', 'paid' => 1],
            ['payment_option' => 'pix', 'amount' => '50.00', 'paid' => 1],
            ['payment_option' => 'invoice', 'amount' => '20.00', 'paid' => 0, 'due_date' => today()->addDay()->toDateString()],
        ]];
        $firstSale = $market->checkout($first->id, $first->version, $payment, $cashier->id);

        $saved = $market->start((string) Str::uuid(), $cashier->id);
        $saved = $market->add($saved->id, $saved->version, $this->product->sku, '1', $cashier->id);
        $close = ['counted_balance' => '79.50', 'request_key' => (string) Str::uuid(), 'reason' => 'lunch'];
        try {
            $shifts->close($morning->id, $close, $cashier->id);
            $this->fail('O caixa foi encerrado com venda ativa.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('shift', $error->errors());
        }
        $saved = $market->transition($saved->id, $saved->version, 'suspended', null, $cashier->id);
        $closed = $shifts->close($morning->id, $close, $cashier->id);
        $this->assertSame('lunch', $closed->closing_reason);
        $this->assertSame('80.00', $closed->expected_balance);
        $this->assertSame('-0.50', $closed->difference);
        $this->assertSame('30.00', $closed->received_by_method['cash']);
        $this->assertSame('50.00', $closed->received_by_method['pix']);
        $this->assertEquals(0, $closed->received_by_method['invoice'] ?? 0);
        $this->assertSame(1, $closed->sale_count);
        $this->assertNull($parallel->fresh()->closed_at);
        $this->assertSame($firstSale->id, $market->checkout($first->id, $first->version, $payment, $cashier->id)->id);
        try {
            $market->transition($saved->id, $saved->version, 'active', null, $cashier->id);
            $this->fail('A venda suspensa foi retomada sem turno aberto.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('shift', $error->errors());
        }
        $afternoon = $this->openTestSupermarketShift($cashier->id, 2, '20.00');
        $saved = $market->transition($saved->id, $saved->version, 'active', null, $cashier->id);
        $market->checkout($saved->id, $saved->version, ['payment_method' => 'cash', 'paid' => true], $cashier->id);
        $closedAgain = $shifts->close($afternoon->id, ['request_key' => (string) Str::uuid(), 'reason' => 'end', 'counted_balance' => '120.00'], $cashier->id);
        $this->assertSame('120.00', $closedAgain->expected_balance);
        $this->assertSame(1, $closedAgain->sale_count);
        $this->assertSame('80.00', $closed->fresh()->expected_balance);
        $otherClose = $shifts->close($parallel->id, ['request_key' => (string) Str::uuid(), 'reason' => 'end', 'counted_balance' => '110.00'], $this->user->id);
        $this->assertSame('110.00', $otherClose->expected_balance);
        $this->assertSame(3, Sale::count());
        $this->assertSame(4, Payment::count());
        $this->assertSame(0, CashClosure::count());
        $this->assertSame(0, SupermarketCart::where('status', 'suspended')->count());
    }

    public function test_supermarket_shift_needs_to_close_before_disabling_the_panel_and_discards_an_empty_ticket(): void
    {
        $this->enableSupermarketForShiftTest($this->user->id);
        $shift = $this->openTestSupermarketShift($this->user->id);
        $settings = $this->operationPayload(['inventory' => false, 'version' => \App\Models\CompanyOperation::findOrFail(1)->version]);
        try {
            app(CompanyOperations::class)->save($settings, $this->user->id);
            $this->fail('A loja desabilitou o painel mesmo com um terminal ocupado.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('operation_modes', $error->errors());
        }
        $idle = app(Supermarket::class)->start((string) Str::uuid(), $this->user->id);
        app(SupermarketShifts::class)->close($shift->id, ['request_key' => (string) Str::uuid(),
            'reason' => 'other', 'counted_balance' => '0.00'], $this->user->id);
        $this->assertSame('cancelled', $idle->fresh()->status);
        $this->assertNull($idle->fresh()->sale_id);
        $this->assertSame(0, Payment::count());
        app(CompanyOperations::class)->save($settings, $this->user->id);
        $this->assertSame(['commerce'], app(CompanyOperations::class)->current()['operation_modes']);
    }

    public function test_supermarket_shift_adopts_only_the_operators_legacy_active_cart_and_keeps_its_items(): void
    {
        $this->enableSupermarketForShiftTest($this->user->id);
        $shifts = app(SupermarketShifts::class);
        $market = app(Supermarket::class);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $line = ['line_key' => (string) Str::uuid(), 'product_id' => $this->product->id, 'sku' => $this->product->sku,
            'description' => $this->product->name, 'unit' => $this->product->unit, 'quantity' => '1.000',
            'unit_price' => '100.00', 'total' => '100.00'];
        $attributes = ['user_id' => $cashier->id, 'items' => [$line], 'status' => 'active', 'total' => '100.00', 'version' => 4];
        $old = SupermarketCart::create($attributes + ['request_key' => (string) Str::uuid()]);
        $foreign = SupermarketCart::create(array_replace($attributes, ['user_id' => $this->user->id, 'request_key' => (string) Str::uuid()]));

        $shift = $this->openTestSupermarketShift($cashier->id);
        $this->assertEquals([$line], $old->fresh()->items);
        $market->checkout($old->id, $old->fresh()->version, ['payment_method' => 'pix', 'paid' => true], $cashier->id);
        $closed = $shifts->close($shift->id, ['request_key' => (string) Str::uuid(), 'reason' => 'end', 'counted_balance' => '0.00'], $cashier->id);
        $this->assertSame(1, $closed->sale_count);
        $this->assertSame('100.00', $closed->received_by_method['pix']);
        $this->assertSame('0.00', $closed->expected_balance);
        $this->assertSame('active', $foreign->fresh()->status);
        $this->assertSame(4, $foreign->fresh()->version);
        $this->assertNull($shifts->current($this->user->id));
    }

    public function test_supermarket_shift_terminal_allows_only_one_concurrent_opening_and_is_isolated_by_store(): void
    {
        $this->enableSupermarketForShiftTest($this->user->id);
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $open = function (int $operator) {
            try {
                return app(SupermarketShifts::class)->open([
                    'request_key' => (string) Str::uuid(), 'opening_balance' => '0', 'terminal_number' => 9,
                ], $operator)->id;
            } catch (ValidationException $error) {
                $this->assertArrayHasKey('terminal_number', $error->errors());
                return 0;
            }
        };
        $results = $this->race(null, fn () => $open($cashier->id), fn () => $open($this->user->id));
        $this->assertCount(1, array_filter($results));
        $this->assertSame(1, SupermarketShift::count());

        app(TenantConnection::class)->connect($this->b);
        $admin = User::create(['name' => 'Loja B', 'email' => 'caixa@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->enableSupermarketForShiftTest($admin->id);
        $this->assertSame(0, SupermarketShift::count());
        $this->assertGreaterThan(0, $open($admin->id));
        $this->assertSame(1, SupermarketShift::count());
    }
}
