<?php

namespace Tests\Support;

use App\Models\{CompanyOperation, FinancialEntry, OperationRecord, Product, RestaurantModifierGroup, RestaurantOrder, Sale, SaleItem, StockMovement, User};
use App\Services\{Checkout, CompanyOperations, Inventory, MeasureUnits, RestaurantCatalog, RestaurantOperations, TenantConnection};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait RestaurantCatalogCases
{
    private function recipeFixture(string $stock = '5'): Product
    {
        app(CompanyOperations::class)->save($this->operationPayload(['operation_modes' => ['restaurant'], 'default_operation' => 'restaurant']), $this->user->id);
        $this->product->update(['controls_stock' => false]);
        $ingredient = Product::create(['name' => 'Ingrediente em KG', 'sku' => 'RECIPE-KG', 'product_group_id' => $this->product->product_group_id,
            'unit' => 'KG', 'controls_stock' => true, 'sale_price' => '20']);
        app(Inventory::class)->move($ingredient->id, 'in', $stock, '20', 'Entrada ingrediente QA', $this->user->id, (string) Str::uuid());
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->post('/app/a/measure-units', ['code' => 'G', 'label' => 'Grama'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/app/a/products/'.$ingredient->id.'/units', ['code' => 'G', 'base' => 'KG', 'purpose' => 'sale', 'factor' => '0.001'])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        return $ingredient;
    }

    private function recipeInput(Product $ingredient): array
    {
        return ['version' => 0, 'station' => 'bar', 'stock_mode' => 'recipe',
            'recipe' => ['ingredients' => [['product_id' => $ingredient->id, 'quantity' => '250', 'measure_unit' => 'G',
                'factor_seen' => MeasureUnits::options($ingredient, app(CompanyOperations::class)->current(), 'sale')['G']]]],
            'groups' => [['name' => 'Complementos', 'min_choices' => 1, 'max_choices' => 1, 'active' => 1,
                'options' => [['name' => 'Porção extra', 'price' => '2.00', 'active' => 1,
                    'ingredient_product_id' => $ingredient->id, 'ingredient_quantity' => '0.025']]]]];
    }

    private function deliveredRecipeOrder(array $modifiers, string $quantity = '2'): RestaurantOrder
    {
        $service = app(RestaurantOperations::class);
        $table = $service->createTable(['name' => 'Mesa '.Str::random(8)], $this->user->id);
        $order = $service->open(['table_id' => $table->id, 'request_key' => (string) Str::uuid()], $this->user->id)->fresh();
        $item = $service->addItem($order->id, ['version' => $order->version, 'request_key' => (string) Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => $quantity, 'modifier_ids' => $modifiers], $this->user->id);
        $service->send($order->id, ['version' => $order->fresh()->version], $this->user->id);
        foreach (['preparing', 'ready'] as $status) $service->transitionItem($order->id, $item->id,
            ['version' => $order->fresh()->version, 'status' => $status], $this->user->id);
        $service->deliverItem($order->id, $item->id, ['version' => $order->fresh()->version], $this->user->id);
        return $order->fresh();
    }

    public function test_restaurant_catalog_requires_group_choices_and_freezes_addon_price_and_default_station_on_retry(): void
    {
        $ingredient = $this->recipeFixture();
        $catalog = app(RestaurantCatalog::class);
        $input = $this->recipeInput($ingredient);
        $aggregated = app(\App\Services\RecipeInventory::class)->quantities(['ingredients' => [
            ['product_id' => $ingredient->id, 'quantity' => '0.001', 'unit' => 'KG'],
            ['product_id' => $ingredient->id, 'quantity' => '0.001', 'unit' => 'KG'],
        ]], '0.500');
        $this->assertSame('0.001', $aggregated[0]['quantity']);
        $input['groups'][0]['options'][] = ['name' => 'Outra porção', 'price' => '3.00', 'active' => 1];
        $config = $catalog->save($this->product->id, $input, $this->user->id);
        $group = RestaurantModifierGroup::with('options')->firstOrFail();
        foreach ([[], $group->options->pluck('id')->all(), [999999], [$group->options[0]->id, $group->options[0]->id]] as $invalid) {
            try { $catalog->quote($this->product, $invalid, '1'); $this->fail('Escolha inválida foi aceita.'); }
            catch (ValidationException $exception) { $this->assertNotEmpty(array_filter(array_keys($exception->errors()), fn ($key) => str_starts_with($key, 'modifier_ids'))); }
        }
        $service = app(RestaurantOperations::class);
        $table = $service->createTable(['name' => 'Mesa adicionais'], $this->user->id);
        $order = $service->open(['table_id' => $table->id, 'request_key' => (string) Str::uuid()], $this->user->id)->fresh();
        $request = ['version' => $order->version, 'request_key' => (string) Str::uuid(), 'product_id' => $this->product->id,
            'quantity' => '1', 'modifier_ids' => [$group->options[0]->id], 'unit_price' => '0.01'];
        $item = $service->addItem($order->id, $request, $this->user->id);
        $this->assertSame('bar', $item->station);
        $this->assertSame('102.00', $item->unit_price);
        $this->assertSame('2.00', $item->commercial_terms['_restaurant_catalog']['addons'][0]['price']);
        $input['version'] = $config->revision;
        $input['station'] = 'kitchen';
        $input['groups'][0]['id'] = $group->id;
        foreach ($input['groups'][0]['options'] as $index => &$option) $option['id'] = $group->options[$index]->id;
        unset($option);
        $input['groups'][0]['options'][0]['price'] = '9.00';
        $catalog->save($this->product->id, $input, $this->user->id);
        $this->assertSame($item->id, $service->addItem($order->id, $request, $this->user->id)->id);
        $this->assertSame('102.00', $item->fresh()->unit_price);
        $this->assertSame('bar', $item->fresh()->station);
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
    }

    public function test_restaurant_recipe_uses_frozen_conversions_only_at_checkout_and_restores_original_stock_once_after_recipe_changes(): void
    {
        $ingredient = $this->recipeFixture();
        $catalog = app(RestaurantCatalog::class);
        $input = $this->recipeInput($ingredient);
        $config = $catalog->save($this->product->id, $input, $this->user->id);
        $group = RestaurantModifierGroup::with('options')->firstOrFail();
        $order = $this->deliveredRecipeOrder([$group->options[0]->id]);
        $this->assertSame('5.000', $ingredient->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $input['version'] = $config->revision;
        $input['recipe']['ingredients'][0]['quantity'] = '500';
        $input['groups'][0]['id'] = $group->id;
        $input['groups'][0]['options'][0]['id'] = $group->options[0]->id;
        $input['groups'][0]['options'][0]['ingredient_quantity'] = '0.100';
        $catalog->save($this->product->id, $input, $this->user->id);
        $payment = ['version' => $order->version, 'payment_method' => 'invoice', 'paid' => false,
            'installments' => 1, 'due_date' => today()->addDay()->toDateString()];
        $sale = app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id);
        $this->assertSame($sale->id, app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id)->id);
        $this->assertSame('204.00', $sale->total);
        $this->assertSame('11.0000', $sale->cost_total);
        $this->assertSame('5.5000', $sale->items()->firstOrFail()->unit_cost);
        $this->assertSame('2.00', OperationRecord::where('action', 'restaurant_sale_options')->firstOrFail()->after['addons'][0]['price']);
        $this->assertSame('4.450', $ingredient->fresh()->quantity);
        $this->assertSame('0.000', $this->product->fresh()->quantity);
        $this->assertSame(0, StockMovement::where('product_id', $this->product->id)->count());
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertSame(1, OperationRecord::where('action', 'restaurant_recipe_consumed')->count());
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->post('/app/a/products/'.$ingredient->id.'/units', ['code' => 'G', 'base' => 'KG', 'purpose' => 'sale', 'factor' => '0.002'])
            ->assertRedirect()->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        app(Checkout::class)->cancel($sale->id, $this->user->id);
        app(Checkout::class)->cancel($sale->id, $this->user->id);
        $this->assertSame('5.000', $ingredient->fresh()->quantity);
        $this->assertSame('20.0000', $ingredient->fresh()->average_cost);
        $this->assertSame(1, StockMovement::where('type', 'return')->count());
        $this->assertSame('cancelled', $sale->fresh()->status);
    }

    public function test_restaurant_recipe_shortage_rolls_back_sale_and_two_parallel_closures_consume_ingredients_once(): void
    {
        $ingredient = $this->recipeFixture('0.500');
        app(RestaurantCatalog::class)->save($this->product->id, $this->recipeInput($ingredient), $this->user->id);
        $option = RestaurantModifierGroup::with('options')->firstOrFail()->options[0];
        $order = $this->deliveredRecipeOrder([$option->id], '3');
        $payment = ['version' => $order->version, 'payment_method' => 'invoice', 'paid' => false, 'due_date' => today()->addDay()->toDateString()];
        try { app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id); $this->fail('Falta de ingrediente foi aceita.'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('quantity', $exception->errors()); }
        $this->assertSame(0, Sale::count());
        $this->assertSame(0, SaleItem::count());
        $this->assertSame(0, FinancialEntry::count());
        $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        $this->assertSame('0.500', $ingredient->fresh()->quantity);
        app(Inventory::class)->move($ingredient->id, 'in', '0.500', '20', 'Reposição QA', $this->user->id, (string) Str::uuid());
        $ids = $this->race(null,
            fn () => app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id)->id,
            fn () => app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id)->id);
        $this->assertSame($ids[0], $ids[1]);
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, StockMovement::where('type', 'sale')->count());
        $this->assertSame('0.175', $ingredient->fresh()->quantity);
    }

    public function test_restaurant_catalog_rejects_invalid_precision_self_ingredients_inactive_stock_and_discloses_cost_only_to_cost_profiles(): void
    {
        $ingredient = $this->recipeFixture();
        $catalog = app(RestaurantCatalog::class);
        $input = $this->recipeInput($ingredient);
        foreach ([['quantity' => '0.001'], ['factor_seen' => '0.002000'], ['product_id' => $this->product->id]] as $change) {
            $bad = $input;
            $bad['recipe']['ingredients'][0] = array_replace($bad['recipe']['ingredients'][0], $change);
            try { $catalog->save($this->product->id, $bad, $this->user->id); $this->fail('Conversão ou ingrediente inválido foi aceito.'); }
            catch (ValidationException) { $this->assertSame(0, \App\Models\RestaurantProductConfig::count()); }
        }
        $catalog->save($this->product->id, $input, $this->user->id);
        $this->get('/app/a/restaurante/catalogo/produtos/'.$this->product->id)->assertOk()->assertSee('Custo estimado dos ingredientes');
        $this->postJson('/app/a/restaurante/catalogo/produtos/'.$this->product->id, $input)
            ->assertUnprocessable()->assertJsonValidationErrors('catalog_complete');
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, \App\Models\RestaurantProductConfig::firstOrFail()->revision);
        $profiles = app(\App\Access\AccessProfiles::class);
        $profile = $profiles->save(['version' => CompanyOperation::findOrFail(1)->version, 'name' => 'Gestor do cardápio QA',
            'permissions' => ['restaurant.catalog.manage']], $this->user->id);
        $actor = User::create(['name' => 'Gestor', 'email' => 'recipe-manager@example.test', 'password' => 'local-qa-password', 'role' => 'operator']);
        DB::connection('tenant')->transaction(fn () => $profiles->assign($actor->id, 'operator', $profile, $this->user->id));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $actor->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante/catalogo/produtos/'.$this->product->id)->assertOk()->assertDontSee('Custo estimado dos ingredientes');
        app(TenantConnection::class)->connect($this->a);
        $waiter = $this->operationalUserForPreset('waiter');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $waiter->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante/catalogo')->assertForbidden();
        $this->post('/app/a/restaurante/catalogo/produtos/'.$this->product->id, $input)->assertForbidden();
        app(TenantConnection::class)->connect($this->b);
        $other = User::create(['name' => 'Admin B', 'email' => 'recipe-b@example.test', 'password' => 'local-qa-password', 'role' => 'admin']);
        app(CompanyOperations::class)->save($this->operationPayload(['operation_modes' => ['restaurant'], 'default_operation' => 'restaurant']), $other->id);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->get('/app/b/restaurante/catalogo/produtos/'.$this->product->id)->assertNotFound();
        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(0, \App\Models\RestaurantProductConfig::count());
    }

    public function test_restaurant_product_stock_with_extra_ingredient_preserves_both_original_valuations_on_cancel(): void
    {
        $ingredient = $this->recipeFixture();
        $this->product->update(['controls_stock' => true]);
        app(Inventory::class)->move($this->product->id, 'in', '4', '10', 'Entrada produto pronto QA', $this->user->id, (string) Str::uuid());
        $input = $this->recipeInput($ingredient);
        $input['stock_mode'] = 'product';
        $input['recipe']['ingredients'] = [];
        app(RestaurantCatalog::class)->save($this->product->id, $input, $this->user->id);
        $option = RestaurantModifierGroup::with('options')->firstOrFail()->options[0];
        $order = $this->deliveredRecipeOrder([$option->id]);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version, 'payment_method' => 'invoice',
            'paid' => false, 'due_date' => today()->addDay()->toDateString()], $this->user->id);
        $this->assertSame('21.0000', $sale->cost_total);
        $saleItem = $sale->items()->firstOrFail();
        $this->assertSame('10.5000', $saleItem->unit_cost);
        $this->assertSame('10.0000', OperationRecord::where('action', 'restaurant_recipe_consumed')->firstOrFail()->after['base_unit_cost']);
        $this->assertSame('2.000', $this->product->fresh()->quantity);
        $this->assertSame('4.950', $ingredient->fresh()->quantity);
        $operator = User::create(['name' => 'Operador sem estorno', 'email' => 'recipe-no-cancel@example.test', 'password' => 'local-qa-password', 'role' => 'operator']);
        try { app(Checkout::class)->cancel($sale->id, $operator->id); $this->fail('Operador sem permissão estornou a venda.'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertSame(0, StockMovement::where('type', 'return')->count());
        $this->assertSame(0, $sale->entries()->whereNotNull('cancelled_at')->count());
        $this->assertSame('2.000', $this->product->fresh()->quantity);
        $this->assertSame('4.950', $ingredient->fresh()->quantity);
        app(Checkout::class)->cancel($sale->id, $this->user->id);
        $this->assertSame('4.000', $this->product->fresh()->quantity);
        $this->assertSame('10.0000', $this->product->fresh()->average_cost);
        $this->assertSame('5.000', $ingredient->fresh()->quantity);
        $this->assertSame('20.0000', $ingredient->fresh()->average_cost);
        $this->assertSame(2, StockMovement::where('type', 'return')->count());
        $this->assertSame('10.0000', StockMovement::where('type', 'return')->where('product_id', $this->product->id)->firstOrFail()->unit_cost);
        $this->assertSame('20.0000000', app(Inventory::class)->movementValue('cancel-'.$saleItem->id));
        $this->assertSame('1.0000000', app(Inventory::class)->movementValue('recipe-cancel-'.$saleItem->id.'-'.$ingredient->id));
    }

    public function test_restaurant_recipe_cannot_close_with_inactive_or_uncontrolled_ingredients_or_inventory_disabled(): void
    {
        $ingredient = $this->recipeFixture();
        $input = $this->recipeInput($ingredient);
        app(RestaurantCatalog::class)->save($this->product->id, $input, $this->user->id);
        $option = RestaurantModifierGroup::with('options')->firstOrFail()->options[0];
        $order = $this->deliveredRecipeOrder([$option->id]);
        $payment = ['version' => $order->version, 'payment_method' => 'invoice', 'paid' => false, 'due_date' => today()->addDay()->toDateString()];
        $attempt = function () use ($order, $payment) {
            try { app(RestaurantOperations::class)->close($order->id, $payment, $this->user->id); $this->fail('Fechamento com ingredientes indisponíveis foi aceito.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('recipe', $error->errors()); }
            $this->assertSame(0, Sale::count());
            $this->assertSame(0, FinancialEntry::count());
            $this->assertSame(0, StockMovement::where('type', 'sale')->count());
        };
        $ingredient->update(['active' => false]);
        $attempt();
        $ingredient->update(['active' => true]);
        app(Inventory::class)->move($ingredient->id, 'out', '5', '0', 'Conciliação para desabilitar QA', $this->user->id, (string) Str::uuid());
        $ingredient->refresh()->update(['controls_stock' => false]);
        $attempt();
        $ingredient->update(['controls_stock' => true]);
        $operations = app(CompanyOperations::class);
        try { $operations->save(array_replace($operations->current(), ['version' => CompanyOperation::findOrFail(1)->version, 'inventory' => false]), $this->user->id); $this->fail('Comanda com ingredientes permitiu desligar estoque.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('inventory', $error->errors()); }
        // Exercise checkout's defense for an old configuration created before the open-order guard.
        $settings = CompanyOperation::findOrFail(1);
        $configuration = $settings->configuration;
        $configuration['inventory'] = false;
        $settings->update(['configuration' => $configuration]);
        $attempt();
        $input['version'] = 1;
        try { app(RestaurantCatalog::class)->save($this->product->id, $input, $this->user->id); $this->fail('Ficha com estoque desabilitado foi aceita.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('recipe', $error->errors()); }
    }

    public function test_restaurant_hidden_ingredient_is_not_sold_from_menu_but_remains_usable_in_recipe_and_legacy_products_stay_visible(): void
    {
        $ingredient = $this->recipeFixture();
        $catalog = app(RestaurantCatalog::class);
        $hidden = $catalog->save($ingredient->id, ['version' => 0, 'station' => 'kitchen', 'stock_mode' => 'product', 'show_on_menu' => 0], $this->user->id);
        $this->assertFalse($hidden->show_on_menu);
        $this->assertSame([$this->product->id], $catalog->menuQuery()->pluck('id')->all());
        $this->assertArrayHasKey($this->product->id, $catalog->publicCatalog([$this->product, $ingredient]));
        $this->assertArrayNotHasKey($ingredient->id, $catalog->publicCatalog([$this->product, $ingredient]));
        try { $catalog->quote($ingredient, [], '1'); $this->fail('Produto oculto foi vendido por ID forjado.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('product_id', $error->errors()); }
        $catalog->save($this->product->id, $this->recipeInput($ingredient), $this->user->id);
        $option = RestaurantModifierGroup::with('options')->firstOrFail()->options[0];
        $order = $this->deliveredRecipeOrder([$option->id]);
        $this->get('/app/a/restaurante/comandas/'.$order->id)->assertOk()->assertDontSee($ingredient->name);
        $this->get('/app/a/restaurante/catalogo')->assertOk()->assertSee($ingredient->name)->assertSee('Oculto');
        app(TenantConnection::class)->connect($this->a);
        $catalog->save($ingredient->id, ['version' => $hidden->revision, 'station' => 'bar', 'stock_mode' => 'product'], $this->user->id);
        $this->assertFalse(\App\Models\RestaurantProductConfig::where('product_id', $ingredient->id)->firstOrFail()->show_on_menu);
        $sale = app(RestaurantOperations::class)->close($order->id, ['version' => $order->version, 'payment_method' => 'invoice',
            'paid' => false, 'due_date' => today()->addDay()->toDateString()], $this->user->id);
        $this->assertSame('11.0000', $sale->cost_total);
    }

    public function test_restaurant_generic_recipe_channels_consume_server_base_recipe_and_block_required_choices_without_trusting_http_snapshots(): void
    {
        $ingredient = $this->recipeFixture();
        $operations = app(CompanyOperations::class);
        $operations->save(array_replace($operations->current(), ['version' => CompanyOperation::findOrFail(1)->version,
            'operation_modes' => ['commerce', 'restaurant', 'supermarket']]), $this->user->id);
        $catalog = app(RestaurantCatalog::class);
        $input = $this->recipeInput($ingredient);
        $input['groups'] = [];
        $config = $catalog->save($this->product->id, $input, $this->user->id);
        $payload = $this->saleData();
        $payload['payment_method'] = 'invoice';
        $payload['paid'] = false;
        $payload['due_date'] = today()->addDay()->toDateString();
        $payload['items'][0]['_restaurant_catalog'] = ['recipe' => ['mode' => 'product', 'ingredients' => []]];
        $sale = app(Checkout::class)->complete($payload, $this->user->id);
        $this->assertSame('5.0000', $sale->cost_total);
        $this->assertSame('4.750', $ingredient->fresh()->quantity);
        app(Checkout::class)->cancel($sale->id, $this->user->id);
        $this->assertSame('5.000', $ingredient->fresh()->quantity);
        $market = app(\App\Services\Supermarket::class);
        $cart = $market->start((string) Str::uuid(), $this->user->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '2', $this->user->id);
        $payment = ['payment_method' => 'invoice', 'paid' => false, 'due_date' => today()->addDay()->toDateString()];
        $marketSale = $market->checkout($cart->id, $cart->version, $payment, $this->user->id);
        $this->assertSame('10.0000', $marketSale->cost_total);
        $this->assertSame('4.500', $ingredient->fresh()->quantity);
        app(Checkout::class)->cancel($marketSale->id, $this->user->id);
        $this->assertSame('5.000', $ingredient->fresh()->quantity);

        $required = $this->recipeInput($ingredient);
        $required['version'] = $config->revision;
        $config = $catalog->save($this->product->id, $required, $this->user->id);
        $cart = $market->start((string) Str::uuid(), $this->user->id);
        $cart = $market->add($cart->id, $cart->version, $this->product->sku, '1', $this->user->id);
        foreach ([fn () => app(Checkout::class)->complete(array_replace($payload, ['request_key' => (string) Str::uuid()]), $this->user->id),
            fn () => $market->checkout($cart->id, $cart->version, $payment, $this->user->id)] as $attempt) {
            try { $attempt(); $this->fail('Ficha com escolhas obrigatórias foi vendida sem comanda.'); }
            catch (ValidationException $error) { $this->assertArrayHasKey('items', $error->errors()); }
        }
        $this->assertSame(2, Sale::count());
        $this->assertSame('5.000', $ingredient->fresh()->quantity);

        $catalog->save($ingredient->id, ['version' => 0, 'station' => 'kitchen', 'stock_mode' => 'product', 'show_on_menu' => 0], $this->user->id);
        $ingredientSale = $payload;
        $ingredientSale['request_key'] = (string) Str::uuid();
        $ingredientSale['items'] = [['kind' => 'product', 'id' => $ingredient->id, 'quantity' => '0.100', 'unit_price' => '20']];
        $this->assertSame('2.0000', app(Checkout::class)->complete($ingredientSale, $this->user->id)->cost_total);
        $this->assertSame('4.900', $ingredient->fresh()->quantity);
        $input['version'] = $config->revision;
        $catalog->save($this->product->id, $input, $this->user->id);
        app(Inventory::class)->move($ingredient->id, 'out', '4.900', '0', 'Conciliação sem inventário QA', $this->user->id, (string) Str::uuid());
        $operations->save(array_replace($operations->current(), ['version' => CompanyOperation::findOrFail(1)->version, 'inventory' => false]), $this->user->id);
        try { app(Checkout::class)->complete(array_replace($payload, ['request_key' => (string) Str::uuid()]), $this->user->id); $this->fail('Ficha foi vendida com inventário desligado.'); }
        catch (ValidationException $error) { $this->assertArrayHasKey('recipe', $error->errors()); }
        $this->assertSame(3, Sale::count());
        $this->assertSame('0.000', $ingredient->fresh()->quantity);
    }
}
