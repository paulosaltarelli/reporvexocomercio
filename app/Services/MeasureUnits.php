<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class MeasureUnits
{
    public const DEFAULTS = ['UN'=>'Unidade','KG'=>'Quilograma','T'=>'Tonelada','M'=>'Metro','L'=>'Litro','CX'=>'Caixa','PC'=>'Peça','PAR'=>'Par'];

    public static function catalog(array $configuration): array
    {
        return self::DEFAULTS + ($configuration['measure_units'] ?? []);
    }

    public static function options(Product $product, array $configuration, string $purpose): array
    {
        $options = [$product->unit => '1'];
        $links = $configuration['unit_links'][$product->id] ?? [];
        if ($product->type === 'product' && ($links['base'] ?? null) === $product->unit) {
            foreach ($links['units'] as $code => $link) {
                if ($link[$purpose]) $options[$code] = $link['factor'];
            }
        }
        return $options;
    }

    public static function convert(Product $product, array $config, array $line, string $purpose): array
    {
        $code = $line['measure_unit'] ?? $product->unit;
        $factor = self::options($product, $config, $purpose)[$code] ?? null;
        if ($factor === null || (isset($line['measure_unit']) && ($line['factor_seen'] ?? '') !== $factor)) {
            throw ValidationException::withMessages(['items'=>'A equivalência mudou. Selecione a unidade novamente.']);
        }
        $quantity = Decimal::input((string)$line['quantity'], 3, 'items', '0.001', '999999999');
        $exact = bcmul($quantity, $factor, 9);
        $base = bcadd($exact, '0', 3);
        if (bccomp($exact, $base, 9) !== 0) {
            throw ValidationException::withMessages(['items'=>'A quantidade convertida deve caber em três casas decimais na unidade de estoque.']);
        }
        Decimal::input($base, 3, 'items', '0.001', $purpose === 'sale' ? '999999' : '999999999');
        $detail = $code === $product->unit ? '' : " · {$quantity} {$code} = {$base} {$product->unit}";
        return [$base, $factor, $detail];
    }
}
