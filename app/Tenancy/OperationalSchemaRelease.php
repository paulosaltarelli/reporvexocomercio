<?php

namespace App\Tenancy;

use RuntimeException;

/** Exact additive schema scope; the preceding certificate and guard policy remain immutable. */
final class OperationalSchemaRelease
{
    public static function profile(): array
    {
        $path = base_path('ops/operational-schema-release.json');
        if (is_link($path) || !is_file($path)) throw new RuntimeException('schema_release_profile_missing');
        $profile = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($profile) || ($profile['version'] ?? null) !== 1
            || !is_string($profile['name'] ?? null) || !preg_match('/^[a-z][a-z0-9-]{1,80}$/D', $profile['name'])
            || !is_array($profile['migrations'] ?? null) || !array_is_list($profile['migrations']) || !$profile['migrations']
            || !is_array($profile['tables'] ?? null) || !array_is_list($profile['tables']) || !$profile['tables']) {
            throw new RuntimeException('schema_release_profile_invalid');
        }
        foreach ($profile['migrations'] as $file) {
            if (!is_string($file) || !preg_match('~^database/tenant-migrations/[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\.php$~D', $file)
                || is_link(base_path($file)) || !is_file(base_path($file))) throw new RuntimeException('schema_release_migration_invalid');
        }
        foreach ($profile['tables'] as $table) {
            if (!is_string($table) || !preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $table)) throw new RuntimeException('schema_release_table_invalid');
        }
        if (count(array_unique($profile['migrations'])) !== count($profile['migrations'])
            || count(array_unique($profile['tables'])) !== count($profile['tables'])) throw new RuntimeException('schema_release_duplicate_scope');
        return $profile;
    }

    public static function migrationNames(): array
    {
        $names = array_map(fn ($path) => basename($path, '.php'), self::profile()['migrations']);
        sort($names);
        return $names;
    }

    public static function assertTransition(array $previous, array $next): void
    {
        $profile = self::profile();
        $newMigrations = array_keys(array_diff_key($next['migrations'] ?? [], $previous['migrations'] ?? []));
        $newTables = array_values(array_diff($next['tables'] ?? [], $previous['tables'] ?? []));
        sort($newMigrations); sort($newTables); $expectedTables = $profile['tables']; sort($expectedTables);
        if ($newMigrations !== self::migrationNames() || $newTables !== $expectedTables) {
            throw new RuntimeException('schema_extension_outside_reviewed_release');
        }
        foreach ($previous['migrations'] ?? [] as $name => $digest) {
            if (($next['migrations'][$name] ?? null) !== $digest) throw new RuntimeException('schema_existing_migration_changed');
        }
        if (($previous['guard_policy_sha256'] ?? null) !== ($next['guard_policy_sha256'] ?? null)
            || array_diff($previous['tables'] ?? [], $next['tables'] ?? [])) throw new RuntimeException('schema_extension_not_additive');
    }
}
