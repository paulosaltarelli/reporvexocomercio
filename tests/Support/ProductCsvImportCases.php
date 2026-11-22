<?php

namespace Tests\Support;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\TenantConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

trait ProductCsvImportCases
{
    private function uploadProductCsv(string $csv, string $company = 'a'): TestResponse
    {
        return $this->post('/app/'.$company.'/products/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('catalogo.csv', $csv),
        ]);
    }

    private function productImportToken(TestResponse $preview): string
    {
        $html = new \DOMDocument();
        @$html->loadHTML($preview->assertOk()->getContent());
        $token = (new \DOMXPath($html))->evaluate('string(//form//input[@name="token"]/@value)');
        $this->assertNotSame('', $token, 'A prévia não ofereceu confirmação do lote válido.');
        return $token;
    }

    public function test_product_csv_import_previews_then_confirms_only_new_products_and_preserves_identifiers_prices_and_stock(): void
    {
        $this->product->update(['barcode' => '0001234567895', 'sale_price' => '42.99']);
        $this->move('in', '2.500', '18.40');
        $before = $this->product->fresh()->getAttributes();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $url = '/app/a/products/import';
        $this->get('/app/a/products')->assertOk()->assertSee($url);
        $this->get($url)->assertOk()->assertSee($url.'/template')->assertSee($url.'/preview');
        $this->get($url.'/template')->assertOk()->assertDownload();
        $csv = "\xEF\xBB\xBFsku;nome;grupo;unidade;preco_venda;codigo_barras\r\n"
            ."000199;\"Café; torrado\";Geral;KG;18,70;0000000123990\r\n"
            ."SKU-1;Outro nome;Geral;UN;0,01;0001234567895\r\n";
        $preview = $this->uploadProductCsv($csv)->assertOk()->assertSee('000199')->assertSee('SKU-1');
        $this->assertSame(['new' => 1, 'existing' => 1, 'error' => 0], $preview->viewData('preview')['counts']);
        $token = $this->productImportToken($preview);
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Product::count());
        $this->assertSame($before, $this->product->fresh()->getAttributes());
        $this->post($url.'/confirm', ['token' => $token, 'rows' => [['sku' => 'FORGED', 'quantity' => '100']]])
            ->assertRedirect($url)->assertSessionHasNoErrors()
            ->assertSessionHas('product_import_result', ['created' => 1, 'skipped' => 1]);
        $this->post($url.'/confirm', ['token' => $token])->assertRedirect($url)->assertSessionHasNoErrors();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(2, Product::count());
        $row = Product::where('sku', '000199')->sole();
        $this->assertSame('Café; torrado', $row->name);
        $this->assertSame('0000000123990', $row->barcode);
        $this->assertSame('KG', $row->unit);
        $this->assertSame('18.70', $row->sale_price);
        $this->assertSame('0.000', $row->quantity);
        $this->assertSame($before, $this->product->fresh()->getAttributes());
        $this->assertSame(1, StockMovement::count());
    }

    public function test_product_csv_import_reports_invalid_rows_without_inserting_any_product_or_creating_groups(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $header = 'sku;nome;grupo;unidade;preco_venda;codigo_barras';
        $invalid = $this->uploadProductCsv(implode("\n", [
            $header, 'VALID;Produto válido;Geral;UN;12,90;', 'INVALID-1;Grupo errado;Grupo inexistente;UN;9,90;',
            'INVALID-2;Unidade errada;Geral;SEMUNIDADE;15,00;', 'INVALID-3;Preço inválido;Geral;UN;10,001;',
            'DUPLICATE;Primeiro;Geral;UN;1,00;', 'DUPLICATE;Segundo;Geral;UN;1,00;',
            'CROSS;Identificação ambígua;Geral;UN;1,00;SKU-1',
        ]))->assertOk()->assertSee('INVALID-1')->assertSee('INVALID-2')->assertSee('INVALID-3');
        $rows = collect($invalid->viewData('preview')['rows'])->keyBy('sku');
        foreach (['INVALID-1', 'INVALID-2', 'INVALID-3', 'DUPLICATE', 'CROSS'] as $sku) {
            $this->assertSame('error', $rows[$sku]['status'], $sku);
            $this->assertNotEmpty($rows[$sku]['message']);
        }
        $html = new \DOMDocument();
        @$html->loadHTML($invalid->getContent());
        $this->assertSame('', (new \DOMXPath($html))->evaluate('string(//form//input[@name="token"]/@value)'));
        $this->postJson('/app/a/products/import/confirm', ['token' => 'invalido'])->assertUnprocessable();
        $many = "sku;nome;grupo;unidade;preco_venda\n";
        foreach (range(1, 201) as $i) {
            $many .= "ITEM-$i;Produto $i;Geral;UN;0,99\n";
        }
        foreach ([$many, "sku;nome;grupo;unidade;preco_venda;quantity\nSALDO;Item;Geral;UN;2,50;90"] as $unusable) {
            $this->postJson('/app/a/products/import/preview', [
                'file' => UploadedFile::fake()->createWithContent('entrada.csv', $unusable),
            ])->assertUnprocessable();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Product::count());
        $this->assertSame(1, ProductGroup::count());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_product_csv_import_revalidates_all_rows_when_another_registration_claims_a_barcode(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $csv = "sku,nome,grupo,unidade,preco_venda,codigo_barras\n"
            ."A-SAVE,Martelo,Geral,UN,\"12,34\",\nB-CONFLICT,Chave,Geral,UN,13.60,7891234567895";
        $token = $this->productImportToken($this->uploadProductCsv($csv));
        app(TenantConnection::class)->connect($this->a);
        $this->product->update(['barcode' => '7891234567895']);
        $this->postJson('/app/a/products/import/confirm', ['token' => $token])->assertUnprocessable();
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Product::count());
        $this->assertFalse(Product::whereIn('sku', ['A-SAVE', 'B-CONFLICT'])->exists());
        $this->assertSame('7891234567895', $this->product->fresh()->barcode);
    }

    public function test_product_csv_import_confirms_a_preview_once_across_two_connections_and_refuses_an_expired_preview(): void
    {
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $csv = "sku;nome;grupo;unidade;preco_venda\nNEW-SKU;Teste das conexões;Geral;UN;1,10";
        $token = $this->productImportToken($this->uploadProductCsv($csv));
        app(TenantConnection::class)->connect($this->a);
        $results = $this->race(null, function () use ($token) {
            return app(\App\Services\ProductCsvImport::class)->confirm($token, $this->user->id)['created'];
        });
        $this->assertSame([1, 1], $results);
        $this->assertSame(1, Product::where('sku', 'NEW-SKU')->count());
        $later = $this->productImportToken($this->uploadProductCsv(str_replace('NEW-SKU', 'EXPIRED', $csv)));
        $this->travel(31)->minutes();
        try {
            $this->postJson('/app/a/products/import/confirm', ['token' => $later])->assertUnprocessable();
        } finally {
            $this->travelBack();
        }
        app(TenantConnection::class)->connect($this->a);
        $this->assertFalse(Product::where('sku', 'EXPIRED')->exists());
        $this->assertSame(0, StockMovement::count());
    }

    public function test_product_csv_import_requires_catalog_management_and_scopes_the_preview_to_operator_and_store(): void
    {
        $cashier = $this->profiledOperator($this->accessProfile(['products.view']));
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $cashier->id, 'auth_version' => 0]);
        $url = '/app/a/products/import';
        $this->get($url)->assertForbidden();
        $this->get($url.'/template')->assertForbidden();
        $csv = "sku;nome;grupo;unidade;preco_venda\nPRIVATE-SKU;Produto A;Geral;UN;12,50";
        $this->uploadProductCsv($csv)->assertForbidden();
        $this->postJson($url.'/confirm', ['token' => 'irrelevante'])->assertForbidden();
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $token = $this->productImportToken($this->uploadProductCsv($csv));
        app(TenantConnection::class)->connect($this->a);
        $other = User::create(['name' => 'Outro administrador', 'email' => 'outro-a@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $other->id, 'auth_version' => 0]);
        $this->postJson($url.'/confirm', ['token' => $token])->assertUnprocessable();
        app(TenantConnection::class)->connect($this->b);
        $owner = new User(['name' => 'Administrador B', 'email' => 'outro-b@example.test', 'password' => 'test-password-123', 'role' => 'admin']);
        $owner->id = $this->user->id;
        $owner->save();
        ProductGroup::create(['name' => 'Geral', 'margin' => '20']);
        $this->withSession(['tenant_id' => $this->b->id, 'user_id' => $owner->id, 'auth_version' => 0]);
        $this->postJson('/app/b/products/import/confirm', ['token' => $token])->assertUnprocessable();
        app(TenantConnection::class)->connect($this->b);
        $this->assertSame(0, Product::count());
        app(TenantConnection::class)->connect($this->a);
        $this->assertSame(1, Product::count());
        $this->withSession(['tenant_id' => $this->a->id, 'user_id' => $this->user->id, 'auth_version' => 0]);
        $this->post($url.'/confirm', ['token' => $token])->assertSessionHasNoErrors();
    }
}
