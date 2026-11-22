<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProductCsvFile
{
    public const COLUMNS = ['sku', 'nome', 'grupo', 'unidade', 'preco_venda', 'codigo_barras'];
    private const MAX_BYTES = 262144;

    public function read(UploadedFile $file): string
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            $this->invalid('Envie um arquivo CSV de até 256 KiB.');
        }
        $contents = file_get_contents($file->getPathname(), false, null, 0, self::MAX_BYTES + 1);
        if ($contents === false || strlen($contents) > self::MAX_BYTES || !mb_check_encoding($contents, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $contents)) {
            $this->invalid('Salve a planilha como CSV em UTF-8, com até 256 KiB.');
        }
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    public function rows(string $contents): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $contents);
        $header = array_shift($lines);
        $separator = str_contains($header, ';') ? ';' : ',';
        $columns = array_map(function ($name) {
            $key = Str::slug(trim((string) $name), '_');
            return ['codigo_de_barras'=>'codigo_barras', 'preco_de_venda'=>'preco_venda'][$key] ?? $key;
        }, str_getcsv($header, $separator, '"', ''));
        if (array_diff(array_slice(self::COLUMNS, 0, 5), $columns) || array_diff($columns, self::COLUMNS)
            || count(array_unique($columns)) !== count($columns)) {
            $this->invalid('Confira o cabeçalho do modelo. Use SKU, nome, grupo, unidade, preco_venda e, opcionalmente, codigo_barras.');
        }
        $rows = [];
        $cell = '(?:"(?:[^"]++|"")*"|[^"'.$separator.']*+)';
        foreach ($lines as $index => $line) {
            if (trim($line) === '') continue;
            if (count($rows) >= 200) $this->invalid('Importe até 200 produtos por arquivo.');
            $values = str_getcsv($line, $separator, '"', '');
            $row = array_fill_keys(self::COLUMNS, '');
            $error = null;
            if (count($values) !== count($columns) || !preg_match('/^'.$cell.'(?:'.$separator.$cell.')*$/D', $line)) {
                $error = 'Confira as aspas e o número de colunas. Cada produto deve ocupar uma linha.';
            } else {
                foreach ($columns as $i => $column) $row[$column] = trim((string) $values[$i]);
            }
            $rows[] = [
                'line' => $index + 2, 'sku' => $row['sku'], 'name' => $row['nome'], 'group' => $row['grupo'],
                'unit' => mb_strtoupper($row['unidade']), 'price' => $row['preco_venda'], 'barcode' => $row['codigo_barras'],
                'error' => $error,
            ];
        }
        if (!$rows) $this->invalid('Inclua pelo menos um produto depois do cabeçalho.');
        return $rows;
    }

    public function price(string $value): ?string
    {
        $value = preg_replace('/^R\$\h*/u', '', $value);
        if (str_contains($value, ',')) {
            if (!preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+),\d{1,2}$/D', $value)) return null;
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        if (!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/D', $value)) return null;
        return bcadd($value, '0', 2);
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
