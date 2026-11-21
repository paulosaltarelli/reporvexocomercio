<?php

namespace App\Services;

use App\Models\OperationRecord;

/** Frozen at checkout; editing the menu cannot change a previously issued receipt. */
final class RestaurantSaleOptions
{
    public function forSale(int $saleId): array
    {
        $result=[];
        foreach (OperationRecord::where('action','restaurant_sale_options')->where('after->sale_id',$saleId)->orderBy('id')->get(['after']) as $record) {
            $snapshot=$record->after;
            if (isset($snapshot['sale_item_id']) && is_array($snapshot['addons']??null)) $result[(int)$snapshot['sale_item_id']]=$snapshot['addons'];
        }
        return $result;
    }
}
