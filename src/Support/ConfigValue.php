<?php

namespace CoreVisys\License\Support;

use Psr\Log\LoggerInterface;

/**
 * Backward-compatibility reader for config keys and their env variables.
 *
 * When a key is renamed, the new name is read first; if only the old name is
 * set, its value is returned and a deprecation warning naming the OLD and NEW
 * variables (names only, never values) is logged. This lets existing installs
 * keep working across a rename without silently breaking.
 */
final class ConfigValue
{
    /**
     * Read a config value, falling back to a deprecated old key when only the
     * old name is present.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $newKey  The current config key.
     * @param  string|null  $oldKey  The deprecated config key, if a rename happened.
     * @param  LoggerInterface|null  $log  Sink for the deprecation warning.
     * @param  string  $configFile  Config file name used in the warning names.
     */
    public static function read(
        array $config,
        string $newKey,
        ?string $oldKey = null,
        ?LoggerInterface $log = null,
        string $configFile = 'corevisys-license',
    ): mixed {
        if (array_key_exists($newKey, $config) && ! self::isBlank($config[$newKey])) {
            return $config[$newKey];
        }

        if ($oldKey !== null && array_key_exists($oldKey, $config) && ! self::isBlank($config[$oldKey])) {
            if ($log !== null) {
                $log->warning('CoreVisys license: deprecated config key used; please migrate to the new name.', [
                    'old' => $configFile.'.'.$oldKey,
                    'new' => $configFile.'.'.$newKey,
                ]);
            }

            return $config[$oldKey];
        }

        return $config[$newKey] ?? null;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }
}
