<?php

namespace Tests\Support;

use App\Models\{FinancialEntry, Payment, RestaurantOrder, RestaurantOrderPayment, RestaurantOrderShare, Sale, StockMovement};
use App\Services\{CompanyOperations, RestaurantOperations, RestaurantPayments};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait RestaurantPaymentCases
{
    private function paymentTestOrder(): RestaurantOrder
    {
        app(CompanyOperations::class)->save($this->operationPayload(['inventory' => false, 'operation_modes' => ['restaurant'], 'default_operation' => 'restaurant']), $this->user->id);
        $this->product->update(['sale_price' => '100.00']);
        $operations = app(RestaurantOperations::class);
        $table = $operations->createTable(['name' => 'Mesa pagamentos'], $this->user->id);
        $order = $operations->open(['table_id' => $table->id, 'request_key' => (string)Str::uuid()], $this->user->id)->fresh();
        $operations->addItem($order->id, ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'product_id' => $this->product->id, 'quantity' => '1', 'station' => 'kitchen'], $this->user->id);
        return $order->fresh();
    }

    private function deliverPaymentTestOrder(RestaurantOrder $order): RestaurantOrder
    {
        $operations = app(RestaurantOperations::class);
        $operations->send($order->id, ['version' => $order->fresh()->version], $this->user->id);
        $item = $order->items()->firstOrFail();
        foreach (['preparing', 'ready'] as $status) $operations->transitionItem($order->id, $item->id,
            ['version' => $order->fresh()->version, 'status' => $status], $this->user->id);
        $operations->deliverItem($order->id, $item->id, ['version' => $order->fresh()->version], $this->user->id);
        return $order->fresh();
    }

    public function test_restaurant_partial_receipt_uses_cash_once_and_attaches_original_entry_when_closing(): void
    {
        $order = $this->paymentTestOrder();
        $payments = app(RestaurantPayments::class);
        $input = ['request_key' => (string)Str::uuid(), 'version' => $order->version, 'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '30.00'];
        $receipt = $payments->receive($order->id, $input, $this->user->id);
        $originalEntry = $receipt->financial_entry_id;
        $originalPayment = $receipt->payment_id;
        $this->assertSame($receipt->id, $payments->receive($order->id, $input, $this->user->id)->id);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::count());
        $this->assertNull(FinancialEntry::findOrFail($originalEntry)->sale_id);
        $this->assertSame('30.00', $payments->summary($order)['received']);
        $this->assertSame('70.00', $payments->summary($order)['balance']);
        try {
            $payments->receive($order->id, array_replace($input, ['amount' => '40.00']), $this->user->id);
            $this->fail('Uma repetição alterada foi aceita.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('request_key', $exception->errors()); }
        $order = $this->deliverPaymentTestOrder($order);
        $close = ['version' => $order->version, 'payment_method' => 'cash', 'payment_option' => 'cash', 'paid' => true];
        $sale = app(RestaurantOperations::class)->close($order->id, $close, $this->user->id);
        $this->assertSame('100.00', $sale->total);
        $this->assertTrue($sale->paid);
        $this->assertSame($sale->id, FinancialEntry::findOrFail($originalEntry)->sale_id);
        $this->assertSame($originalPayment, $receipt->fresh()->payment_id);
        $this->assertSame(2, Payment::where('kind', 'payment')->count());
        $this->assertSame('100.00', (string)FinancialEntry::where('sale_id', $sale->id)->sum('amount'));
        $this->assertSame($sale->id, app(RestaurantOperations::class)->close($order->id, $close, $this->user->id)->id);
        $this->assertSame(1, Sale::count());
        $this->assertSame(2, Payment::count());
        $this->assertSame('0.00', $payments->summary($order->fresh())['balance']);
    }

    public function test_restaurant_person_and_item_allocations_preserve_cents_and_require_refund_before_cancellation(): void
    {
        $order = $this->paymentTestOrder();
        $payments = app(RestaurantPayments::class);
        $division = ['request_key' => (string)Str::uuid(), 'version' => $order->version, 'people_count' => 3, 'names' => ['Ana', 'Bia', 'Caio']];
        $shares = $payments->divide($order->id, $division, $this->user->id);
        $this->assertSame(['33.33', '33.33', '33.34'], array_map(fn ($share) => $share->amount, $shares));
        $this->assertCount(3, $payments->divide($order->id, $division, $this->user->id));
        $person = $payments->receive($order->id, ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'allocation_type' => 'person', 'share_id' => $shares[0]->id, 'payment_option' => 'cash'], $this->user->id);
        $this->assertSame('33.33', $person->base_amount);
        $item = $order->items()->firstOrFail();
        $selected = $payments->receive($order->id, ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'allocation_type' => 'items', 'items' => [['item_id' => $item->id, 'quantity' => '0.3']], 'payment_option' => 'pix'], $this->user->id);
        $this->assertSame('30.00', $selected->amount);
        $this->assertSame('0.700', $payments->summary($order)['item_remaining'][0]['remaining_quantity']);
        try {
            app(RestaurantOperations::class)->cancelItem($order->id, $item->id, ['version' => $order->fresh()->version, 'reason' => 'Pedido cancelado'], $this->user->id);
            $this->fail('Item com pagamento foi cancelado.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('item', $exception->errors()); }
        $refund = ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version, 'reason' => 'Devolução confirmada ao cliente', 'refund_confirmed' => true];
        $payments->refund($order->id, $selected->id, $refund, $this->user->id);
        $payments->refund($order->id, $selected->id, $refund, $this->user->id);
        $this->assertSame(1, Payment::where('kind', 'refund')->count());
        $this->assertSame('1.000', $payments->summary($order)['item_remaining'][0]['remaining_quantity']);
        $payments->cancelDivision($order->id, $division['request_key'], ['version' => $order->fresh()->version, 'reason' => 'Cliente mudou a divisão'], $this->user->id);
        $this->assertSame([], $payments->summary($order)['shares']);
        $this->assertSame('33.33', $payments->summary($order)['received']);
        $this->assertSame(3, RestaurantOrderShare::whereNotNull('cancelled_at')->count());
    }

    public function test_restaurant_receipts_serialize_competing_payments_and_full_prepaid_close_has_no_new_payment(): void
    {
        $order = $this->paymentTestOrder();
        $payments = app(RestaurantPayments::class);
        $input = ['request_key' => (string)Str::uuid(), 'version' => $order->version, 'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '100.00'];
        $receive = fn () => $payments->receive($order->id, $input, $this->user->id)->id;
        $ids = $this->race(null, $receive, $receive);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, RestaurantOrderPayment::count());
        $this->assertSame(1, Payment::count());
        $order = $this->deliverPaymentTestOrder($order);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version], $this->user->id);
        $this->assertTrue($sale->paid);
        $this->assertSame('100.00', $sale->total);
        $this->assertSame(1, FinancialEntry::where('sale_id', $sale->id)->count());
        $this->assertSame(1, Payment::count());
        $this->assertSame('100.00', $payments->summary($order->fresh())['received']);
    }
    public function test_restaurant_prepaid_card_fee_is_not_charged_twice_when_linking_the_sale(): void
    {
        $order = $this->paymentTestOrder();
        app(\App\Services\CardFees::class)->save(['version' => \App\Models\CompanyOperation::findOrFail(1)->version,
            'name' => 'Crédito com taxa', 'type' => 'credit', 'policy' => 'pass', 'active' => true, 'rates' => [1 => '5.00']], $this->user->id);
        $brand = array_key_first(app(\App\Services\CardFees::class)->options('credit'));
        $payments = app(RestaurantPayments::class);
        $receipt = $payments->receive($order->id, ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'allocation_type' => 'amount', 'payment_option' => 'credit', 'amount' => '30.00', 'card_brand' => $brand,
            'card_installments' => 1, 'fee_policy' => 'pass', 'rate_seen' => '5.00', 'charged_seen' => '31.58', 'fee_reviewed' => true], $this->user->id);
        $this->assertSame('31.58', $receipt->amount);
        $this->assertSame('1.58', $receipt->passed_fee);
        $this->assertSame('70.00', $payments->summary($order)['balance']);
        $order = $this->deliverPaymentTestOrder($order);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version,
            'payment_option' => 'cash', 'payment_method' => 'cash', 'paid' => true], $this->user->id);
        $this->assertSame('101.58', $sale->total);
        $this->assertTrue($sale->paid);
        $this->assertSame('101.58', (string)FinancialEntry::where('sale_id', $sale->id)->sum('amount'));
        $this->assertSame(1, FinancialEntry::where('direction', 'out')->count());
        $this->assertSame('1.58', (string)FinancialEntry::where('direction', 'out')->sum('amount'));
        $this->assertSame(3, Payment::count());
        $this->assertSame('101.58', $payments->summary($order->fresh())['received']);
    }

    public function test_restaurant_different_receipts_cannot_overpay_or_refund_after_the_sale_is_closed(): void
    {
        $order = $this->paymentTestOrder();
        $payments = app(RestaurantPayments::class);
        $inputs = [];
        foreach ([1, 2] as $part) $inputs[] = ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '60.00'];
        $receive = function (array $input) use ($payments, $order) {
            try { return $payments->receive($order->id, $input, $this->user->id)->id; }
            catch (ValidationException $exception) { return 0; }
        };
        $ids = $this->race(null, fn () => $receive($inputs[0]), fn () => $receive($inputs[1]));
        $this->assertCount(1, array_filter($ids));
        $this->assertSame(1, Payment::count());
        $this->assertSame('40.00', $payments->summary($order)['balance']);
        $order = $this->deliverPaymentTestOrder($order);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version,
            'payment_option' => 'cash', 'payment_method' => 'cash', 'paid' => true], $this->user->id);
        try {
            $payments->refund($order->id, array_values(array_filter($ids))[0], ['request_key' => (string)Str::uuid(),
                'version' => $order->fresh()->version, 'reason' => 'Tentativa depois do fechamento', 'refund_confirmed' => true], $this->user->id);
            $this->fail('Recebimento vinculado à venda foi estornado diretamente.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('order', $exception->errors()); }
        $this->assertSame('100.00', $sale->total);
        $this->assertSame(0, Payment::where('kind', 'refund')->count());
    }

    public function test_restaurant_last_receipt_must_clear_balance_and_two_hundred_prepaid_titles_close_once(): void
    {
        $order = $this->paymentTestOrder();
        $payments = app(RestaurantPayments::class);
        $first = $payments->receive($order->id, ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '0.01'], $this->user->id);
        // Populate valid historical ledger rows directly; exercise the service only at the boundary.
        for ($index = 1; $index < 199; $index++) {
            $entry = FinancialEntry::findOrFail($first->financial_entry_id)->replicate();
            $entry->request_key = (string)Str::uuid();
            $entry->schedule_key = (string)Str::uuid();
            $entry->save();
            $payment = Payment::findOrFail($first->payment_id)->replicate();
            $payment->request_key = (string)Str::uuid();
            $payment->entry_id = $entry->id;
            $payment->save();
            $receipt = $first->replicate();
            $receipt->request_key = (string)Str::uuid();
            $receipt->financial_entry_id = $entry->id;
            $receipt->payment_id = $payment->id;
            $receipt->save();
        }
        $input = ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '0.01'];
        try {
            $payments->receive($order->id, $input, $this->user->id);
            $this->fail('O último recebimento deixou um saldo sem possibilidade de fechamento.');
        } catch (ValidationException $exception) { $this->assertArrayHasKey('amount', $exception->errors()); }
        $this->assertSame(199, Payment::count());
        $last = $payments->receive($order->id, array_replace($input, ['amount' => '98.01']), $this->user->id);
        $this->assertSame($last->id, $payments->receive($order->id, array_replace($input, ['amount' => '98.01']), $this->user->id)->id);
        $order = $this->deliverPaymentTestOrder($order);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version], $this->user->id);
        $this->assertTrue($sale->paid);
        $this->assertSame('100.00', $sale->total);
        $this->assertSame(200, FinancialEntry::where('sale_id', $sale->id)->count());
        $this->assertSame(200, Payment::count());
    }

    private function assertRestaurantPaymentForbidden(callable $operation): void
    {
        try { $operation(); $this->fail('Uma operação financeira foi aceita sem autorização atual.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function test_restaurant_payment_mutations_recheck_live_permission_and_active_actor_before_replay(): void
    {
        $order = $this->paymentTestOrder();
        $profiles = app(\App\Access\AccessProfiles::class);
        $profile = $profiles->save(['version' => $profiles->state(true)['version'], 'name' => 'Recebedor restaurante QA',
            'permissions' => ['restaurant.receive']], $this->user->id);
        $actor = \Illuminate\Support\Facades\DB::connection('tenant')->transaction(function () use ($profiles, $profile) {
            $user = \App\Models\User::create(['name' => 'Caixa parcial QA', 'email' => 'caixa-parcial@example.test',
                'password' => 'test-password-123', 'role' => 'operator', 'active' => true]);
            $profiles->assign($user->id, 'operator', $profile, $this->user->id);
            return $user;
        });
        $payments = app(RestaurantPayments::class);
        $division = ['request_key' => (string)Str::uuid(), 'version' => $order->version, 'people_count' => 2];
        $payments->divide($order->id, $division, $actor->id);
        $input = ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '10.00'];
        $receipt = $payments->receive($order->id, $input, $actor->id);
        $refund = ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'reason' => 'Devolução sem perfil autorizado', 'refund_confirmed' => true];
        $this->assertRestaurantPaymentForbidden(fn () => $payments->refund($order->id, $receipt->id, $refund, $actor->id));
        $profiles->save(['version' => $profiles->state(true)['version'], 'name' => 'Recebedor restaurante QA',
            'permissions' => ['restaurant.use']], $this->user->id, $profile);
        foreach ([
            fn () => $payments->receive($order->id, $input, $actor->id),
            fn () => $payments->divide($order->id, $division, $actor->id),
            fn () => $payments->cancelDivision($order->id, $division['request_key'], ['version' => $order->fresh()->version, 'reason' => 'Cancelamento negado'], $actor->id),
            fn () => $payments->refund($order->id, $receipt->id, $refund, $actor->id),
            fn () => \Illuminate\Support\Facades\DB::connection('tenant')->transaction(fn () => $payments->settle($order->id, new Sale, [], $actor->id)),
        ] as $attempt) $this->assertRestaurantPaymentForbidden($attempt);
        $actor->update(['active' => false]);
        $this->assertRestaurantPaymentForbidden(fn () => $payments->receive($order->id, $input, $actor->id));
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, Payment::where('kind', 'refund')->count());
        $this->assertSame('10.00', $payments->summary($order)['received']);
    }

    public function test_restaurant_dba_receipt_and_refund_replays_require_current_unrevoked_grant(): void
    {
        $order = $this->paymentTestOrder();
        $actor = \App\Models\User::create(['name' => 'DBA financeiro QA', 'email' => 'dba-restaurante@example.test',
            'password' => 'test-password-123', 'role' => \App\Models\User::ROLE_INSTALLER, 'active' => true]);
        $admin = $this->licensingAdmin();
        $grant = \App\Platform\InstallerInvitation::create(['tenant_id' => $this->a->id, 'issued_by' => $admin->id,
            'user_id' => $actor->id, 'name' => $actor->name, 'email' => $actor->email, 'status' => 'accepted',
            'delivery_status' => 'sent', 'access_profile' => 'tenant_dba', 'accepted_at' => now()]);
        $payments = app(RestaurantPayments::class);
        $input = ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'allocation_type' => 'amount', 'payment_option' => 'cash', 'amount' => '10.00'];
        $receipt = $payments->receive($order->id, $input, $actor->id);
        $refund = ['request_key' => (string)Str::uuid(), 'version' => $order->fresh()->version,
            'reason' => 'Devolução financeira confirmada', 'refund_confirmed' => true];
        $payments->refund($order->id, $receipt->id, $refund, $actor->id);
        $this->assertSame($receipt->id, $payments->refund($order->id, $receipt->id, $refund, $actor->id)->id);
        foreach ([['status' => 'revoked'], ['status' => 'accepted', 'access_profile' => 'installer']] as $change) {
            $grant->update($change);
            $this->assertRestaurantPaymentForbidden(fn () => $payments->receive($order->id, $input, $actor->id));
            $this->assertRestaurantPaymentForbidden(fn () => $payments->refund($order->id, $receipt->id, $refund, $actor->id));
        }
        $grant->update(['access_profile' => 'tenant_dba']);
        $actor->update(['active' => false]);
        $this->assertRestaurantPaymentForbidden(fn () => $payments->receive($order->id, $input, $actor->id));
        $this->assertRestaurantPaymentForbidden(fn () => $payments->refund($order->id, $receipt->id, $refund, $actor->id));
        $this->assertSame(1, Payment::where('kind', 'payment')->count());
        $this->assertSame(1, Payment::where('kind', 'refund')->count());
        $this->assertSame('0.00', $payments->summary($order)['received']);
    }

    public function test_restaurant_operations_recheck_each_permission_and_block_inactive_actor_replays(): void
    {
        $this->paymentTestOrder();
        $operations = app(RestaurantOperations::class);
        $table = $operations->createTable(['name' => 'Mesa autorização de domínio'], $this->user->id);
        $actor = \App\Models\User::create(['name' => 'Garçom de domínio', 'email' => 'garcom-dominio@example.test',
            'password' => 'test-password-123', 'role' => 'operator', 'active' => true]);
        $profiles = app(\App\Access\AccessProfiles::class);
        $profile = $profiles->save(['version' => $profiles->state(true)['version'], 'name' => 'Garçom domínio QA',
            'permissions' => ['restaurant.use', 'restaurant.cancel']], $this->user->id);
        \Illuminate\Support\Facades\DB::connection('tenant')->transaction(fn () => $profiles->assign($actor->id, 'operator', $profile, $this->user->id));
        $opening = ['table_id' => $table->id, 'request_key' => (string)Str::uuid()];
        $order = $operations->open($opening, $actor->id)->fresh();
        $adding = ['request_key' => (string)Str::uuid(), 'version' => $order->version,
            'product_id' => $this->product->id, 'quantity' => '1', 'station' => 'kitchen'];
        $item = $operations->addItem($order->id, $adding, $actor->id);
        $profiles->save(['version' => $profiles->state(true)['version'], 'name' => 'Garçom domínio QA',
            'permissions' => ['restaurant.use']], $this->user->id, $profile);
        $version = ['version' => $order->fresh()->version];
        foreach ([
            fn () => $operations->cancelItem($order->id, $item->id, $version + ['reason' => 'Cancelamento negado'], $actor->id),
            fn () => $operations->cancel($order->id, $version + ['reason' => 'Cancelamento negado'], $actor->id),
            fn () => $operations->createTable(['name' => 'Mesa negada'], $actor->id),
            fn () => $operations->transfer($order->id, $version + ['request_key' => (string)Str::uuid(), 'table_id' => $table->id, 'reason' => 'Transferência negada'], $actor->id),
            fn () => $operations->transitionItem($order->id, $item->id, $version + ['status' => 'preparing'], $actor->id),
            fn () => $operations->deliverItem($order->id, $item->id, $version, $actor->id),
            fn () => $operations->close($order->id, $version, $actor->id),
        ] as $attempt) $this->assertRestaurantPaymentForbidden($attempt);
        $profiles->save(['version' => $profiles->state(true)['version'], 'name' => 'Garçom domínio QA',
            'permissions' => []], $this->user->id, $profile);
        $this->assertRestaurantPaymentForbidden(fn () => $operations->open($opening, $actor->id));
        $this->assertRestaurantPaymentForbidden(fn () => $operations->addItem($order->id, $adding, $actor->id));
        $this->assertRestaurantPaymentForbidden(fn () => $operations->send($order->id, $version, $actor->id));
        $actor->update(['role' => 'admin', 'active' => false]);
        $this->assertRestaurantPaymentForbidden(fn () => $operations->createTable(['name' => 'Mesa inativa negada'], $actor->id));
        $this->assertRestaurantPaymentForbidden(fn () => $operations->addItem($order->id, $adding, $actor->id));
        $this->assertSame('draft', $item->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, Payment::count());
    }

}
