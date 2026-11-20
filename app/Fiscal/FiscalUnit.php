<?php

namespace App\Fiscal;

use App\Models\OperationRecord;
use App\Models\Product;
use Illuminate\Validation\ValidationException;

final class FiscalUnit
{
    public static function symbol(Product $product): ?string
    {
        $unit = (string) $product->unit;
        if ($unit !== '' && mb_strlen($unit) <= 6) {
            return $unit;
        }
        $record = OperationRecord::where('action', 'fiscal_unit_alias')
            ->where('after->product_id', (int) $product->id)->where('after->base', $unit)->latest('id')->first();
        return $record?->after['symbol'] ?? null;
    }

    public static function required(Product $product): string
    {
        return self::symbol($product) ?? throw ValidationException::withMessages([
            'fiscal' => $product->name.': cadastre em Fiscal → Regra do produto uma sigla equivalente de até seis caracteres para a unidade '.$product->unit.'.',
        ]);
    }
}
