<?php

namespace App\Fiscal;

final class Snapshot
{
    /** MySQL JSON may reorder object keys; hash a canonical representation. */
    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function sort(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }
        foreach ($data as &$value) {
            if ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            }
            if (is_array($value)) {
                $value = self::sort($value);
            }
        }

        return $data;
    }
}
