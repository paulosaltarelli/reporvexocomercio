<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\User;
use App\Services\CompanyOperations;
use App\Services\ProductImages;
use App\Services\TenantConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

trait OperationalImageCases
{
    private function productImageUrl(int $id): string
    {
        return route('product.image', ['company' => 'a', 'id' => $id]);
    }

    private function productImageBytes($response): string
    {
        return file_get_contents($response->baseResponse->getFile()->getPathname());
    }

    public function test_product_image_upload_is_sanitized_replaced_removed_and_never_publicly_cached(): void
    {
        Storage::fake('local');
        $id = $this->product->id;
        $get = $this->productImageUrl($id);
        $save = route('product.image.save', ['company' => 'a', 'id' => $id]);
        $this->get($get)->assertRedirect('/app/a/login');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get($get)->assertNotFound();
        $this->post($save, [])->assertSessionHasErrors('product_image');
        $invalid = [
            UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('pretend.png', 'not a PNG'),
            UploadedFile::fake()->image('too-small.png', 47, 48),
            UploadedFile::fake()->image('too-wide.png', 2049, 48),
            UploadedFile::fake()->image('too-large.png', 96, 96)->size(2049),
        ];
        foreach ($invalid as $file) $this->post($save, ['product_image' => $file])->assertSessionHasErrors('product_image');
        $this->assertSame([], Storage::disk('local')->allFiles());
        $file = UploadedFile::fake()->image('catalog.jpg', 1600, 1000);
        file_put_contents($file->getRealPath(), '<?php IMAGE-QA-MARKER', FILE_APPEND);
        $this->post($save, ['product_image' => $file, 'image_path' => 'tenants/'.$this->b->id.'/unsafe.png'])
            ->assertRedirect(route('product.edit', ['company' => 'a', 'id' => $id]))->assertSessionHasNoErrors();
        $images = app(ProductImages::class);
        $first = $images->presentation('a', $this->a->id, $id);
        $this->assertFalse($first['sheet']);
        $this->assertStringNotContainsString('/storage/', $first['url']);
        $response = $this->get($first['url'])->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $bytes = $this->productImageBytes($response);
        $this->assertStringNotContainsString('IMAGE-QA-MARKER', $bytes);
        $this->assertSame([800, 500, IMAGETYPE_PNG], array_slice(getimagesizefromstring($bytes), 0, 3));
        $this->assertFalse($images->exists($this->b->id, $id));
        $this->post($save, ['product_image' => UploadedFile::fake()->image('replacement.png', 72, 96)])
            ->assertRedirect()->assertSessionHasNoErrors();
        $second = $images->presentation('a', $this->a->id, $id);
        $this->assertNotSame($first['url'], $second['url']);
        $this->assertSame([72, 96], array_slice(getimagesizefromstring($this->productImageBytes($this->get($first['url'])->assertOk())), 0, 2));
        $this->post($save, ['product_image' => UploadedFile::fake()->image('conflicting.png', 96, 96), 'remove_product_image' => 1])
            ->assertSessionHasErrors('product_image');
        $this->assertTrue($images->exists($this->a->id, $id));
        $this->post($save, ['remove_product_image' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($second['url'])->assertNotFound();
        $this->assertNull($images->presentation('a', $this->a->id, $id));
        Storage::disk('local')->assertMissing($images->relativePath($this->a->id, $id));
        $this->get('/app/a/products/'.$id.'/edit')->assertOk();
    }

    public function test_product_image_atlas_remains_tenant_private_and_upload_overrides_only_its_own_tile(): void
    {
        Storage::fake('local');
        $images = app(ProductImages::class);
        $other = Product::create(['name' => 'Segundo item com imagem', 'sku' => 'IMAGE-2',
            'product_group_id' => $this->product->product_group_id, 'sale_price' => '12', 'unit' => 'UN']);
        $sheet = UploadedFile::fake()->image('atlas.png', 192, 96);
        $original = $sheet->get();
        $images->storeSheet($this->a->id, [$this->product->id => 0, $other->id => 1], $sheet, 2, 1);
        $first = $images->presentation('a', $this->a->id, $this->product->id);
        $second = $images->presentation('a', $this->a->id, $other->id);
        $this->assertTrue($first['sheet']);
        $this->assertSame(200, $first['size_x']);
        $this->assertSame($first['url'], $second['url']);
        $this->assertEquals(0, $first['position_x']);
        $this->assertEquals(100, $second['position_x']);
        $this->assertSame($images->file($this->a->id, $this->product->id), $images->file($this->a->id, $other->id));
        $this->assertSame($original, file_get_contents($images->file($this->a->id, $other->id)));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $response = $this->get($first['url'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame($original, $this->productImageBytes($response));
        $etag = $response->headers->get('ETag');
        $this->assertNotEmpty($etag);
        $this->withHeaders(['If-None-Match' => $etag])->get($first['url'])->assertStatus(304);
        $this->flushHeaders();
        $this->get(route('product.image', ['company' => 'b', 'id' => $other->id]))->assertRedirect('/app/b/login');

        app(TenantConnection::class)->connect($this->b);
        $otherAdmin = User::forceCreate(['id' => $this->user->id, 'name' => 'Admin B', 'email' => 'image-b@example.test',
            'password' => 'test-password-123', 'role' => 'admin']);
        $group = \App\Models\ProductGroup::create(['name' => 'Grupo B', 'margin' => '0']);
        Product::forceCreate(['id' => $other->id, 'name' => 'Produto B sem imagem', 'sku' => 'B-IMAGE',
            'product_group_id' => $group->id, 'sale_price' => '1', 'unit' => 'UN']);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $otherAdmin->id, 'auth_version' => 0]);
        $this->get(route('product.image', ['company' => 'b', 'id' => $other->id, 'v' => basename($images->file($this->a->id, $other->id))]))->assertNotFound();
        $this->withHeaders(['If-None-Match' => $etag])->get(str_replace('/app/a/', '/app/b/', $first['url']))->assertNotFound();
        $this->flushHeaders();
        $this->assertFalse($images->exists($this->b->id, $other->id));

        app(TenantConnection::class)->connect($this->a);
        $images->store($this->a->id, $this->product->id, UploadedFile::fake()->image('own.png', 96, 72));
        $this->assertFalse($images->presentation('a', $this->a->id, $this->product->id)['sheet']);
        $this->assertTrue($images->presentation('a', $this->a->id, $other->id)['sheet']);
        $images->remove($this->a->id, $this->product->id);
        $this->assertNull($images->presentation('a', $this->a->id, $this->product->id));
        $this->assertSame($original, file_get_contents($images->file($this->a->id, $other->id)));
        $images->remove($this->a->id, $other->id);
        $this->assertNull($images->presentation('a', $this->a->id, $other->id));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->withHeaders(['If-None-Match' => $etag])->get($first['url'])->assertNotFound();
        $this->flushHeaders();
    }

    public function test_product_image_permissions_require_catalog_or_enabled_operational_access_and_never_allow_operational_upload(): void
    {
        Storage::fake('local');
        $operations = app(CompanyOperations::class);
        $operations->save($this->operationPayload(['inventory' => false, 'operation_modes' => ['restaurant', 'supermarket'], 'default_operation' => 'restaurant']), $this->user->id);
        $images = app(ProductImages::class);
        $images->storeSheet($this->a->id, [$this->product->id => 0], UploadedFile::fake()->image('atlas.png', 96, 96), 1, 1);
        $sheetUrl = $images->presentation('a', $this->a->id, $this->product->id)['url'];
        $images->store($this->a->id, $this->product->id, UploadedFile::fake()->image('own.png', 96, 96));
        // Upload removes this map entry; seed it again to exercise both authorization paths.
        $images->storeSheet($this->a->id, [$this->product->id => 0], UploadedFile::fake()->image('atlas.png', 96, 96), 1, 1);
        $url = $this->productImageUrl($this->product->id);
        foreach (['waiter' => true, 'supermarket_cashier' => true, 'kitchen' => false] as $preset => $allowed) {
            $actor = $this->operationalUserForPreset($preset);
            $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $actor->id, 'auth_version' => 0]);
            $response = $this->get($url);
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $response = $this->get($sheetUrl);
            $allowed ? $response->assertOk() : $response->assertForbidden();
            $this->post(route('product.image.save', ['company' => 'a', 'id' => $this->product->id]), ['remove_product_image' => 1])->assertForbidden();
            $this->get($this->productImageUrl(999999))->assertStatus($allowed ? 404 : 403);
            app(TenantConnection::class)->connect($this->a);
        }
        $waiter = User::where('email', 'waiter@example.test')->firstOrFail();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $waiter->id, 'auth_version' => 0]);
        $this->product->update(['active' => false]);
        $this->get($url)->assertNotFound();
        $this->get($sheetUrl)->assertNotFound();
        app(TenantConnection::class)->connect($this->a);
        $this->product->update(['active' => true]);
        $operations->save($this->operationPayload(['version' => \App\Models\CompanyOperation::findOrFail(1)->version,
            'inventory' => false, 'operation_modes' => ['supermarket'], 'default_operation' => 'supermarket']), $this->user->id);
        $this->get($url)->assertForbidden();
        $this->get($sheetUrl)->assertForbidden();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get($url)->assertOk();
        $this->post(route('product.image.save', ['company' => 'a', 'id' => 999999]), ['remove_product_image' => 1])->assertNotFound();
    }

    public function test_product_image_tampered_map_and_symbolic_links_never_read_a_foreign_file(): void
    {
        Storage::fake('local');
        $images = app(ProductImages::class);
        $images->storeSheet($this->a->id, [$this->product->id => 0], UploadedFile::fake()->image('sheet.png', 96, 96), 1, 1);
        $base = 'tenants/'.$this->a->id.'/catalog/products/';
        $valid = json_decode(Storage::disk('local')->get($base.'images.json'), true);
        $bad = $valid;
        $bad[$this->product->id]['sheet'] = '../../../../foreign.png';
        Storage::disk('local')->put('foreign.png', 'PRIVATE-FOREIGN-CONTENT');
        Storage::disk('local')->put($base.'images.json', json_encode($bad));
        try {
            (new ProductImages)->file($this->a->id, $this->product->id);
            $this->fail('Mapa com travessia de caminho foi aceito.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('PRIVATE-FOREIGN-CONTENT', $exception->getMessage());
        }
        $this->assertNull((new ProductImages)->presentation('a', $this->a->id, $this->product->id));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->get($this->productImageUrl($this->product->id))->assertNotFound();
        $this->get('/app/a/products/'.$this->product->id.'/edit')->assertOk()->assertSee('Nenhuma imagem cadastrada');
        $this->post(route('product.image.save', ['company' => 'a', 'id' => $this->product->id]),
            ['product_image' => UploadedFile::fake()->image('safe.png', 96, 96)])->assertSessionHasErrors('product_image');
        $this->assertSame($bad, json_decode(Storage::disk('local')->get($base.'images.json'), true));
        Storage::disk('local')->put($base.'images.json', json_encode($valid));
        $single = Storage::disk('local')->path($images->relativePath($this->a->id, $this->product->id));
        symlink(Storage::disk('local')->path('foreign.png'), $single);
        try {
            (new ProductImages)->file($this->a->id, $this->product->id);
            $this->fail('Link simbólico foi servido.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('PRIVATE-FOREIGN-CONTENT', $exception->getMessage());
        } finally {
            unlink($single);
        }
        $this->assertSame('PRIVATE-FOREIGN-CONTENT', Storage::disk('local')->get('foreign.png'));
        $this->assertNotNull((new ProductImages)->presentation('a', $this->a->id, $this->product->id));
    }

    public function test_operational_refinements_use_persisted_times_categories_and_actual_repeated_scan_instead_of_last_array_line(): void
    {
        Storage::fake('local');
        $this->freezeTime();
        app(CompanyOperations::class)->save($this->operationPayload(['inventory' => false,
            'operation_modes' => ['restaurant', 'supermarket'], 'default_operation' => 'restaurant']), $this->user->id);
        $waiter = $this->operationalUserForPreset('waiter');
        $kitchen = $this->operationalUserForPreset('kitchen');
        $cashier = $this->operationalUserForPreset('supermarket_cashier');
        $group = \App\Models\ProductGroup::create(['name' => 'Bebidas QA', 'margin' => '0']);
        $other = Product::create(['name' => 'QA Suco natural', 'sku' => 'IMAGE-SUCO', 'product_group_id' => $group->id,
            'sale_price' => '25', 'unit' => 'UN']);
        $this->product->update(['name' => 'QA Arroz branco']);
        app(ProductImages::class)->storeSheet($this->a->id, [$this->product->id => 0, $other->id => 1],
            UploadedFile::fake()->image('catalog.png', 192, 96), 2, 1);
        $restaurant = app(\App\Services\RestaurantOperations::class);
        $table = $restaurant->createTable(['name' => 'Mesa ocupada QA'], $this->user->id);
        $restaurant->createTable(['name' => 'Mesa livre QA'], $this->user->id);
        $order = $restaurant->open(['table_id' => $table->id, 'request_key' => (string) \Illuminate\Support\Str::uuid()], $this->user->id)->fresh();
        $order->update(['created_at' => now()->subMinutes(47)]);
        $item = $restaurant->addItem($order->id, ['version' => $order->version, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'product_id' => $this->product->id, 'quantity' => '1', 'station' => 'kitchen', 'notes' => 'Sem sal'], $this->user->id);
        $restaurant->send($order->id, ['version' => $order->fresh()->version], $this->user->id);
        $item->update(['sent_at' => now()->subMinutes(13)]);
        $openedAt = $order->fresh()->created_at->toIso8601String();
        $sentAt = $item->fresh()->sent_at->toIso8601String();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $waiter->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante')->assertOk()->assertSee('Livre')->assertSee('Ocupada')
            ->assertSee('Em atendimento há 47 min')->assertSee($openedAt);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $kitchen->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante/producao')->assertOk()->assertSee('Há 13 min desde o envio')
            ->assertSee($sentAt)->assertSee('Sem sal');
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $waiter->id, 'auth_version' => 0]);
        $this->get('/app/a/restaurante/comandas/'.$order->id)->assertOk()->assertSee('Bebidas QA')
            ->assertSee('data-category-chips', false)->assertSee('data-product-search', false)
            ->assertSee('product-artwork-sheet', false)->assertDontSee('Nenhuma imagem cadastrada');

        app(TenantConnection::class)->connect($this->a);
        $market = app(\App\Services\Supermarket::class);
        $cart = $market->start((string) \Illuminate\Support\Str::uuid(), $cashier->id);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $cashier->id, 'auth_version' => 0]);
        $base = '/app/a/supermarket/carts/'.$cart->id;
        foreach ([$this->product, $other, $this->product] as $product) {
            app(TenantConnection::class)->connect($this->a);
            $post = $this->post($base.'/items', ['version' => $cart->fresh()->version, 'code' => $product->sku, 'quantity' => '1'])
                ->assertRedirect($base)->assertSessionHasNoErrors();
            app(TenantConnection::class)->connect($this->a);
            $expectedLine = collect($cart->fresh()->items)->firstWhere('product_id', $product->id);
            $post->assertSessionHas('last_supermarket_scan', ['cart_id' => $cart->id, 'line_key' => $expectedLine['line_key']]);
            $screen = $this->get($base.'?q=QA')->assertOk();
            $dom = new \DOMDocument();
            @$dom->loadHTML('<?xml encoding="UTF-8">'.$screen->getContent());
            $last = (new \DOMXPath($dom))->query('//*[contains(concat(" ",normalize-space(@class)," ")," operation-last-scan ")]');
            $this->assertSame(1, $last->length);
            $this->assertStringContainsString($product->name, $last->item(0)->textContent);
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame($other->id, \Illuminate\Support\Arr::last($cart->fresh()->items)['product_id']);
        $this->assertStringNotContainsString($other->name, $last->item(0)->textContent);
        $this->assertStringContainsString('quantidade acumulada na venda', $last->item(0)->textContent);
        $this->assertStringContainsString('2,000 UN', $last->item(0)->textContent);
        $this->assertStringContainsString('200,00', $last->item(0)->textContent);
        $this->assertSame('225.00', $cart->fresh()->total);
        $this->postJson($base.'/items', ['version' => $cart->fresh()->version, 'code' => '9999999999999', 'quantity' => '1'])->assertUnprocessable();
        $this->get($base)->assertOk()->assertDontSee('operation-last-scan', false);
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame('225.00', $cart->fresh()->total);
    }
}
