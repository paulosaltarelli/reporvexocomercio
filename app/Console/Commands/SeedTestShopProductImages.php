<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Tenant;
use App\Services\ProductImages;
use App\Services\TenantConnection;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/** Explicit test-shop artwork import; never changes product records or balances. */
final class SeedTestShopProductImages extends Command
{
    protected $signature = 'vendra:test-shop-images {company} {fixture} {sheet} {columns} {rows} {--confirm-test-shop}';
    protected $description = 'Vincula imagens ilustrativas por SKU somente às lojas de teste 3 e 4, sem alterar cadastros.';

    public function handle(TenantConnection $connection, ProductImages $images): int
    {
        if (!$this->option('confirm-test-shop')) {
            $this->error('Confirme --confirm-test-shop para vincular as imagens da demonstração.');
            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', $this->argument('company'))->where('active', true)->firstOrFail();
        if (!in_array((int)$tenant->id, [3,4], true)) {
            $this->error('Este comando está restrito às lojas de teste 3 e 4.');
            return self::FAILURE;
        }
        $fixturePath = $this->argument('fixture');
        $sheetPath = $this->argument('sheet');
        if (!is_file($fixturePath) || !is_readable($fixturePath) || filesize($fixturePath)>1024*1024
            || !is_file($sheetPath) || !is_readable($sheetPath) || filesize($sheetPath)>32*1024*1024) {
            $this->error('Informe os arquivos locais de referência e PNG, dentro dos limites permitidos.');
            return self::FAILURE;
        }
        try {
            $fixture = json_decode(file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);
            $products = $fixture['products'] ?? null;
            $columns = filter_var($this->argument('columns'), FILTER_VALIDATE_INT);
            $rows = filter_var($this->argument('rows'), FILTER_VALIDATE_INT);
            if (!is_array($products) || !$products || $columns<1 || $columns>12 || $rows<1 || $rows>12 || count($products)>$columns*$rows) throw new \InvalidArgumentException('Referência ou grade de imagens inválida.');
            $skus = [];
            foreach (array_values($products) as $index=>$product) {
                $sku = $product['sku'] ?? null;
                if (!is_string($sku) || $sku==='' || strlen($sku)>80 || isset($skus[$sku])) throw new \InvalidArgumentException('Todos os produtos da referência precisam de SKU único.');
                $skus[$sku] = $index;
            }
            $connection->connect($tenant);
            $records = Product::whereIn('sku',array_keys($skus))->get(['id','sku']);
            if ($records->count()!==count($skus) || $records->pluck('sku')->unique()->count()!==count($skus)) throw new \InvalidArgumentException('Nem todos os SKUs da referência foram encontrados de forma única nesta loja. Nenhuma imagem foi vinculada.');
            $map = [];
            foreach ($records as $product) $map[$product->id] = $skus[$product->sku];
            $images->storeSheet($tenant->id, $map, new UploadedFile($sheetPath, basename($sheetPath),'image/png',null,true),$columns,$rows);
            $this->info(count($map).' imagens ilustrativas vinculadas por SKU à loja de teste '.$tenant->id.'. Cadastros e saldos preservados.');
            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        } finally {
            $connection->disconnect();
        }
    }
}
