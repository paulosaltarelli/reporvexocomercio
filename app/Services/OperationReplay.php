<?php

namespace App\Services;

use App\Models\OperationRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

final class OperationReplay
{
    /** Keep text identifiers intact. Normalize decimals only in quantity and money fields. */
    private static function normalize(array $data): array
    {
        if (!array_is_list($data)) ksort($data);
        foreach ($data as $key => &$value) {
            if (is_array($value)) {
                $value = self::normalize($value);
            } elseif ($value !== null) {
                $value = (string)(is_bool($value) ? (int)$value : $value);
                if (in_array($key, ['quantity','unit_price','unit_cost','amount','discount_percent','catalog_price_seen','rate_seen','charged_seen','factor_seen'], true)
                    && preg_match('/^\d+\.\d+$/D', $value)) {
                    $value = rtrim(rtrim($value, '0'), '.');
                }
            }
        }
        return $data;
    }

    private static function digest(array $payload): string
    {
        return hash('sha256', json_encode(self::normalize($payload), JSON_THROW_ON_ERROR));
    }

    public static function record(Model $model, array $payload, int $actor): void
    {
        OperationRecord::create(['action'=>$model->getTable().'_request','version'=>1,'user_id'=>$actor,
            'before'=>[],'after'=>['id'=>$model->getKey(),'hash'=>self::digest($payload)],'created_at'=>now()]);
    }

    public static function verify(Model $model, array $payload, int $actor): void
    {
        $record = OperationRecord::where('action', $model->getTable().'_request')
            ->where('after->id', $model->getKey())->sharedLock()->first();
        if (!$record || (int)$record->user_id !== $actor || !hash_equals($record->after['hash'], self::digest($payload))) {
            throw ValidationException::withMessages(['request_key'=>'Esta tentativa já foi registrada. Confira a operação #'.$model->getKey().' antes de iniciar outra.']);
        }
    }
}
