<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\SupermarketCart;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\Supermarket;
use App\Services\TenantConnection;
use App\Services\WeightedLabels;
use Illuminate\Support\Str;

trait SupermarketSearchCases
{
    public function test_supermarket_entry_searches_names_without_adding_and_preserves_quantity_and_exact_code_priority(): void
    {
        app(CompanyOperations::class)->save($this->operationPayload([
            'inventory' => false, 'segment' => 'supermarket', 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket',
            'weighed_labels' => array_replace(WeightedLabels::DEFAULTS, ['enabled' => true]),
        ]), $this->user->id);
        $this->product->update(['name' => 'Cerveja Clara', 'sku' => 'BEER-01', 'barcode' => '7891234567890']);
        $dark = $this->product->replicate();
        $dark->fill(['name' => 'Cerveja Escura', 'sku' => 'BEER-02', 'barcode' => null])->save();
        $cart = app(Supermarket::class)->start((string) Str::uuid(), $this->user->id);
        $path = '/app/a/supermarket/carts/'.$cart->id;
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);

        $response = $this->post($path.'/items', ['version' => 0, 'code' => 'Cerveja', 'quantity' => '3']);
        $response->assertRedirect(route('supermarket.show', ['company' => 'a', 'cart' => $cart->id, 'q' => 'Cerveja', 'search_quantity' => '3.000']))
            ->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame([], $cart->fresh()->items);
        $this->assertSame(0, $cart->fresh()->version);
        $search = $this->get($path.'?q=Cerveja&search_quantity=3.000')->assertOk()
            ->assertViewHas('searchQuantity', '3.000')->assertSee('Cerveja Clara')->assertSee('Cerveja Escura');
        file_put_contents('/tmp/supermarket-search-many.html', $search->getContent());
        $empty = $this->get($path.'?q=NomeInexistente&search_quantity=3.000')->assertOk()->assertSee('Nenhum produto encontrado');
        file_put_contents('/tmp/supermarket-search-empty.html', $empty->getContent());
        $this->get($path.'?q=Cerveja&search_quantity=3.1234')->assertSessionHasErrors('search_quantity');

        $this->post($path.'/items', ['version' => 0, 'code' => 'BEER-01', 'quantity' => '3.000'])
            ->assertRedirect($path)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('3.000', $cart->fresh()->items[0]['quantity']);
        $this->assertSame(1, $cart->fresh()->version);
        $this->post($path.'/items', ['version' => 1, 'code' => 'Escura', 'quantity' => '0.250'])
            ->assertRedirect(route('supermarket.show', ['company' => 'a', 'cart' => $cart->id, 'q' => 'Escura', 'search_quantity' => '0.250']))
            ->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertCount(1, $cart->fresh()->items);
        $this->assertSame(1, $cart->fresh()->version);
        $single = $this->get($path.'?q=Escura&search_quantity=0.250')->assertOk()->assertSee('Cerveja Escura');
        file_put_contents('/tmp/supermarket-search-single.html', $single->getContent());

        app(TenantConnection::class)->connect($this->a);
        $exact = $this->product->replicate();
        $exact->fill(['name' => 'Água mineral', 'sku' => 'Cerveja', 'barcode' => null])->save();
        $this->post($path.'/items', ['version' => 1, 'code' => 'Cerveja', 'quantity' => '2'])
            ->assertRedirect($path)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($exact->id, $cart->fresh()->items[1]['product_id']);
        $this->assertSame('2.000', $cart->fresh()->items[1]['quantity']);
        $this->assertSame(2, $cart->fresh()->version);

        $this->postJson($path.'/items', ['version' => 2, 'code' => '9999999999999', 'quantity' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $base = '200000100750';
        $invalid = $base.(string) (((int) WeightedLabels::checkDigit($base) + 1) % 10);
        $this->postJson($path.'/items', ['version' => 2, 'code' => $invalid, 'quantity' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson($path.'/items', ['version' => 0, 'code' => 'Outra cerveja', 'quantity' => '3'])
            ->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson($path.'/items', ['version' => 2, 'code' => 'Outra cerveja', 'quantity' => '3.1234'])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
        app(TenantConnection::class)->connect($this->a);
        $other = User::create(['name' => 'Outro caixa', 'email' => 'search-other@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->postJson($path.'/items', ['version' => 2, 'code' => 'Outra cerveja', 'quantity' => '3'])->assertNotFound();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, $cart->fresh()->version);
        $this->assertCount(2, $cart->fresh()->items);
    }
}
