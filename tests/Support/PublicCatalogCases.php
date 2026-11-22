<?php

namespace Tests\Support;

use App\Models\BusinessProfile;
use App\Models\CompanyOperation;
use App\Models\Product;
use App\Models\User;
use App\Platform\Contract;
use App\Services\CompanyOperations;
use App\Services\ProductImages;
use App\Services\TenantConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

trait PublicCatalogCases
{
    public function test_storefront_requires_explicit_publication_and_serves_only_public_product_columns(): void
    {
        Storage::fake('local');
        $storefront = '/app/a/catalogo-publico';
        $settings = '/app/a/business/catalogo-publico';
        $this->product->update(['name' => 'Produto em vitrine', 'sku' => 'SKU-INTERNO-700']);
        BusinessProfile::forceCreate(['id' => 1, 'legal_name' => 'Razão interna', 'trade_name' => 'Loja Horizonte', 'email' => 'interno@exemplo.test']);
        $others = [];
        foreach (['Desativado' => ['active' => false], 'Serviço particular' => ['type' => 'service']] as $name => $change) {
            $others[] = Product::create($change + ['name' => $name, 'sku' => $name, 'product_group_id' => $this->product->product_group_id, 'sale_price' => '3.00']);
        }
        $userSession = ['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0];
        $this->get($storefront)->assertNotFound();
        $this->get($storefront.'/logo')->assertNotFound();
        app(TenantConnection::class)->connect($this->a);
        $operator = User::create(['name' => 'Operador', 'email' => 'catalog@example.test', 'password' => 'test-password-123', 'role' => 'operator']);
        $this->withSession(array_replace($userSession, ['user_id' => $operator->id]))
            ->post($settings, ['visibility' => 'public', 'version' => 0, 'publish_all' => 1])->assertForbidden();
        $this->withSession($userSession)->post($settings, ['visibility' => 'public', 'version' => 0])->assertSessionHasErrors('publish_all');
        $this->post($settings, ['visibility' => 'public', 'version' => 0, 'publish_all' => 1])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/app/a/business')->assertOk()->assertSee('Copiar link')->assertSee('Abrir catálogo');
        app(TenantConnection::class)->connect($this->a);
        $config = app(CompanyOperations::class);
        $config->save($this->operationPayload(['version' => CompanyOperation::findOrFail(1)->version, 'quotes' => false]), $this->user->id);
        $this->assertTrue($config->current()['public_catalog_published']);
        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            if (str_contains($event->sql, 'from `products`')) $queries[] = $event->sql;
        });
        $page = $this->get($storefront)->assertOk()->assertSee('Produto em vitrine')->assertSee('R$ 100,00')->assertSee('Loja Horizonte')
            ->assertDontSee('Desativado')->assertDontSee('Serviço particular')->assertDontSee('SKU-INTERNO-700')->assertDontSee('interno@exemplo.test');
        $this->assertEmpty($page->headers->getCookies());
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertNotEmpty($queries);
        foreach ($queries as $sql) {
            $this->assertStringContainsString('select `id`, `name`, `unit`, `sale_price`', $sql);
            $this->assertStringNotContainsString('cost', $sql);
        }
        app(TenantConnection::class)->connect($this->a);
        $images = app(ProductImages::class);
        $images->storeSheet($this->a->id, [$this->product->id => 0, $others[0]->id => 1], UploadedFile::fake()->image('sheet.png', 128, 64), 2, 1);
        $this->get($storefront)->assertSee('/produtos/'.$this->product->id.'/foto', false)->assertDontSee('/images/sheets', false);
        $photo = $this->get($storefront.'/produtos/'.$this->product->id.'/foto')->assertOk()->assertHeader('Content-Type', 'image/png');
        $size = getimagesize($photo->baseResponse->getFile()->getPathname());
        $this->assertSame([64, 64], [$size[0], $size[1]]);
        $this->get($storefront.'/produtos/'.$others[0]->id.'/foto')->assertNotFound();
        $this->get($storefront.'/logo')->assertOk();
        app(TenantConnection::class)->connect($this->a);
        $version = CompanyOperation::findOrFail(1)->version;
        $this->post($settings, ['visibility' => 'private', 'version' => 0])->assertSessionHasErrors('version');
        $this->post($settings, ['visibility' => 'private', 'version' => $version])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($storefront)->assertNotFound();
        $this->get($storefront.'/produtos/'.$this->product->id.'/foto')->assertNotFound();
        $this->get($storefront.'/logo')->assertNotFound();
    }

    public function test_storefront_paginates_and_searches_without_touching_another_stores_session_and_respects_contract(): void
    {
        Storage::fake('local');
        $config = app(CompanyOperations::class);
        $config->publishCatalog(true, 0, $this->user->id);
        foreach (range(1, 25) as $n) {
            Product::create(['name' => sprintf('Café %02d', $n), 'sku' => 'CAF-'.$n, 'product_group_id' => $this->product->product_group_id, 'sale_price' => '4.80']);
        }
        app(TenantConnection::class)->connect($this->b);
        $other = User::create(['name' => 'Outra loja', 'email' => 'externo@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->get('/app/a/catalogo-publico?q=Caf')->assertOk()->assertSee('Café 01')->assertSee('Café 24')->assertDontSee('Café 25')->assertDontSee('Produto teste')->assertSee('Próxima');
        $this->get('/app/a/catalogo-publico?q=Caf&page=2')->assertOk()->assertSee('Café 25')->assertDontSee('Café 24');
        $this->get('/app/a/catalogo-publico?q=%25')->assertOk()->assertSee('Nenhum produto encontrado');
        $this->get('/app/a/catalogo-publico?q[]=invalid')->assertStatus(422);
        $this->get('/app/b/catalogo-publico')->assertNotFound();
        $this->get('/app/a/catalogo-publico/logo')->assertOk();
        $this->get('/app/b/business')->assertOk()->assertSessionHas('tenant_id', $this->b->id);
        $contract = Contract::create(['tenant_id' => $this->a->id, 'start_date' => today()->subMonth(), 'end_date' => today()->addYear(),
            'monthly_amount' => '12.00', 'due_day' => 1, 'restricted_modules' => ['catalog']]);
        $debt = $contract->installments()->create(['tenant_id' => $this->a->id, 'period' => today()->startOfMonth(), 'due_date' => today()->subDay(), 'amount' => '12.00']);
        $this->get('/app/a/catalogo-publico')->assertNotFound()->assertDontSee('parcela vencida');
        $this->get('/app/a/catalogo-publico/logo')->assertNotFound();
        $debt->update(['cancelled_at' => now()]);
        $this->get('/app/a/catalogo-publico')->assertOk();
        $this->a->update(['active' => false]);
        $this->get('/app/a/catalogo-publico')->assertNotFound();
    }
}
