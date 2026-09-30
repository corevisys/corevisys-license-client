<?php

namespace CoreVisys\License\Support;

/**
 * Shared normalization for config values sourced from the environment.
 *
 * An explicitly-empty environment value (e.g. `COREVISYS_LICENSE_SERVER_URL=`)
 * is treated the same as "unset" so the documented default applies. This
 * restores the pre-2.x behavior where a blank value never overrode the
 * default. The logic runs when the config file is evaluated, so it is safe
 * under `php artisan config:cache` (the cached array holds the resolved value).
 */
final class ConfigDefaults
{
    public static function normalize(mixed $value, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($value) && trim($value) === '') {
            return $default;
        }

        return $value;
    }
}
