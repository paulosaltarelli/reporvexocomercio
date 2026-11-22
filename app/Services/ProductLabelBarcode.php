<?php

namespace App\Services;

use App\Models\Product;
use Com\Tecnick\Barcode\Barcode;

final class ProductLabelBarcode
{
    public function forProduct(Product $product): ?array
    {
        foreach (['barcode', 'sku'] as $field) {
            $code = (string) $product->$field;
            if (!$product->getAttribute($field.'_unique') || $code !== trim($code) || !preg_match('/^[\x20-\x7E]{1,80}$/D', $code)) continue;
            $ean = $field === 'barcode' && preg_match('/^[0-9]{13}$/D', $code)
                && WeightedLabels::checkDigit(substr($code, 0, 12)) === $code[12];
            $type = $ean ? 'EAN13' : 'C128';
            $symbol = (new Barcode)->getBarcodeObj($type, $code)->getArray();
            // Twelve empty modules on each side. On the smaller stock keep each bar at least 0.25 mm wide.
            $modules = $symbol['ncols'] + 24;
            if ($modules > 280) continue;
            return ['type' => $type, 'code' => $code, 'field' => $field, 'modules' => $modules, 'bars' => $symbol['bars']];
        }
        return null;
    }
}
