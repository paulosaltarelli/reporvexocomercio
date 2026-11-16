<?php

namespace App\Tenancy;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\MySqlProcessor;

/** Only installed after the shared credential has passed __vendra_identity. */
final class SharedMySqlProcessor extends MySqlProcessor
{
    public const SINGLETONS=['business_profiles','fiscal_settings','company_operations','cash_closure_locks'];

    public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
    {
        $id=parent::processInsertGetId($query,$sql,$values,$sequence);
        // The certified shared-schema trigger assigns ID1 before insertion. MySQL
        // returns 0 when the trigger supplied the ID instead of AUTO_INCREMENT.
        if ((int)$id===0 && is_string($query->from) && in_array($query->from,self::SINGLETONS,true)) {
            return 1;
        }
        return $id;
    }
}
