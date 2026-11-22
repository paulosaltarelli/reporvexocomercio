<?php

namespace App\Services;

use App\Models\CompanyOperation;
use App\Models\OperationRecord;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ProductCsvImport
{
    public function __construct(private ProductCsvFile $csv, private CompanyOperations $operations) {}

    public function template(): string
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, ProductCsvFile::COLUMNS, ';', '"', '', "\r\n");
        $group = ProductGroup::orderBy('id')->value('name');
        // A group name is store-supplied data that will be opened in a spreadsheet.
        if (is_string($group) && !preg_match('/^\s*[=+@\x2D]|[\x00-\x1F]/u', $group)) {
            fputcsv($stream, ['EXEMPLO-001', 'Produto de exemplo', $group, 'UN', '12,50', ''], ';', '"', '', "\r\n");
        }
        rewind($stream);
        $bytes = stream_get_contents($stream);
        fclose($stream);
        return $bytes;
    }

    public function preview(UploadedFile $file, int $actorId): array
    {
        InstallerAccess::authorizedActor($actorId, 'products.manage');
        $source = $this->csv->read($file);
        $review = $this->review($source, false);
        $token = null;
        if ($review['counts']['new'] > 0 && !$review['counts']['error']) {
            $token = Crypt::encryptString(json_encode([
                'purpose' => 'product-csv', 'tenant' => $this->tenantId(), 'actor' => $actorId,
                'expires' => now()->addMinutes(30)->getTimestamp(), 'id' => (string) Str::uuid(),
                'source' => $source, 'digest' => $this->digest($review),
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return ['rows' => $review['rows'], 'counts' => $review['counts'], 'token' => $token];
    }

    public function confirm(string $token, int $actorId): array
    {
        $preview = $this->openToken($token, $actorId);
        try {
            return DB::connection('tenant')->transaction(function () use ($preview, $actorId) {
                CompanyOperation::insertOrIgnore(['id'=>1, 'configuration'=>json_encode(CompanyOperations::DEFAULTS), 'version'=>0]);
                CompanyOperation::whereKey(1)->lockForUpdate()->firstOrFail();
                InstallerAccess::authorizedActor($actorId, 'products.manage');
                $receipt = OperationRecord::where('action', 'product_csv_imported')->where('after->request_key', $preview['id'])
                    ->lockForUpdate()->first();
                if ($receipt) return $receipt->after['result'];

                $review = $this->review($preview['source'], true);
                if (!hash_equals($preview['digest'], $this->digest($review))) {
                    $this->invalidToken('O catálogo ou a configuração de estoque mudou. Faça uma nova prévia antes de importar.');
                }
                $create = app(QuickCreate::class);
                foreach ($review['rows'] as $row) {
                    if ($row['status'] !== 'new') continue;
                    $create->create('products', [
                        'request_key' => Uuid::uuid5($preview['id'], 'linha-'.$row['line'])->toString(),
                        'name' => $row['name'], 'sku' => $row['sku'], 'barcode' => $row['barcode'] === '' ? null : $row['barcode'],
                        'product_group_id' => $row['group_id'], 'unit' => $row['unit'], 'sale_price' => $row['price'],
                        'controls_stock' => $review['controls_stock'],
                    ], $actorId);
                }
                $result = ['created'=>$review['counts']['new'], 'skipped'=>$review['counts']['existing']];
                OperationRecord::create(['version'=>0, 'user_id'=>$actorId, 'action'=>'product_csv_imported', 'before'=>[],
                    'after'=>['request_key'=>$preview['id'], 'digest'=>$preview['digest'], 'result'=>$result], 'created_at'=>now()]);
                return $result;
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            $this->invalidToken('Um dos códigos acabou de ser cadastrado. Confira uma nova prévia.');
        }
    }

    private function review(string $source, bool $lock): array
    {
        $config = $this->operations->current();
        $this->operations->assertItem('product', $config);
        $units = MeasureUnits::catalog($config);
        $rows = $this->csv->rows($source);
        $duplicates = $this->duplicateLines($rows);
        $groups = [];
        $counts = ['new'=>0, 'existing'=>0, 'error'=>0];
        foreach ($rows as &$row) {
            $row['group_id'] = null;
            $row['target_id'] = null;
            $error = $row['error'] ?? $this->validateRow($row, $units);
            unset($row['error']);
            if (!$error && isset($duplicates[$row['line']])) $error = 'SKU ou código de barras repetido em outra linha do arquivo.';
            if (!$error) {
                if (!array_key_exists($row['group'], $groups)) {
                    $query = ProductGroup::where('name', $row['group']);
                    $groups[$row['group']] = ($lock ? $query->sharedLock() : $query)->value('id');
                }
                $row['group_id'] = $groups[$row['group']];
                if (!$row['group_id']) $error = 'Grupo não encontrado. Use o nome de um grupo cadastrado nesta loja.';
            }
            if (!$error) {
                $codes = [$row['sku']];
                if ($row['barcode'] !== '') $codes[] = $row['barcode'];
                $query = Product::where(fn ($q) => $q->whereIn('sku', $codes)->orWhereIn('barcode', $codes))
                    ->select(['id', 'type'])->selectRaw('sku = ? AS same_sku, barcode = ? AS same_barcode', [$row['sku'], $row['barcode']])
                    ->orderBy('id')->limit(2);
                $matches = ($lock ? $query->lockForUpdate() : $query)->get();
                if ($matches->isNotEmpty()) {
                    $item = $matches->first();
                    if ($matches->count() !== 1 || !$item->same_sku || $item->type !== 'product'
                        || ($row['barcode'] !== '' && !$item->same_barcode)) {
                        $error = 'SKU ou código de barras conflita com um cadastro da loja.';
                    } else {
                        $row['target_id'] = (int) $item->id;
                    }
                }
            }
            $row['status'] = $error ? 'error' : ($row['target_id'] ? 'existing' : 'new');
            $row['message'] = $error ?? ($row['target_id'] ? 'SKU já cadastrado. O produto será preservado.' : 'Pronto para cadastrar.');
            ++$counts[$row['status']];
        }
        unset($row);
        return ['rows'=>$rows, 'counts'=>$counts, 'controls_stock'=>(bool) $config['inventory']];
    }

    private function validateRow(array &$row, array $units): ?string
    {
        foreach (['name'=>['Nome',180], 'sku'=>['SKU',80], 'group'=>['Grupo',255], 'unit'=>['Unidade',10]] as $field => [$label,$max]) {
            if ($row[$field] === '' || mb_strlen($row[$field]) > $max) return $label.' precisa ter entre 1 e '.$max.' caracteres.';
        }
        if (mb_strlen($row['barcode']) > 80) return 'O código de barras aceita até 80 caracteres.';
        if (!isset($units[$row['unit']])) return 'Use a sigla de uma unidade de medida cadastrada na loja.';
        $price = $this->csv->price($row['price']);
        if ($price === null) return 'Informe um preço de venda entre R$ 0,00 e R$ 999.999.999.999,99 com até duas casas decimais.';
        $row['price'] = $price;
        return null;
    }

    /** Compare identifiers with the tenant database's accent- and case-insensitive collation. */
    private function duplicateLines(array $rows): array
    {
        $codes = [];
        foreach ($rows as $row) {
            foreach (['sku', 'barcode'] as $field) {
                if ($row[$field] !== '' && mb_strlen($row[$field]) <= 80) {
                    $codes[] = ['line'=>$row['line'], 'code'=>$row[$field]];
                }
            }
        }
        if (!$codes) return [];
        $matches = DB::connection('tenant')->select(<<<'SQL'
            WITH input_codes AS (
                SELECT row_no, identifier COLLATE utf8mb4_unicode_ci AS code
                FROM JSON_TABLE(?, '$[*]' COLUMNS (row_no INT PATH '$.line', identifier VARCHAR(80) CHARACTER SET utf8mb4 PATH '$.code')) AS entries
            )
            SELECT DISTINCT row_no FROM input_codes
            JOIN (SELECT code FROM input_codes GROUP BY code HAVING COUNT(DISTINCT row_no) > 1) AS duplicates USING (code)
            SQL, [json_encode($codes, JSON_THROW_ON_ERROR)]);
        return array_fill_keys(array_column($matches, 'row_no'), true);
    }

    private function openToken(string $token, int $actorId): array
    {
        if (strlen($token) > 1500000) $this->invalidToken('Prévia inválida. Envie novamente o arquivo.');
        try {
            $data = json_decode(Crypt::decryptString($token), true, 32, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $e) {
            $this->invalidToken('Prévia inválida. Envie novamente o arquivo.');
        }
        if (($data['purpose'] ?? null) !== 'product-csv' || ($data['tenant'] ?? null) !== $this->tenantId()
            || ($data['actor'] ?? null) !== $actorId || ($data['expires'] ?? 0) <= now()->getTimestamp()) {
            $this->invalidToken('Esta prévia não está disponível para confirmação. Envie novamente o arquivo.');
        }
        return $data;
    }

    private function digest(array $review): string
    {
        return hash('sha256', json_encode($review, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function tenantId(): int
    {
        $id = (int) DB::connection('tenant')->getConfig('vendra_tenant_id');
        abort_unless($id > 0, 403);
        return $id;
    }

    private function invalidToken(string $message): never
    {
        throw ValidationException::withMessages(['token' => $message]);
    }
}
