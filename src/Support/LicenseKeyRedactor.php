<?php

namespace CoreVisys\License\Support;

/**
 * Central redaction for license keys and other secrets derived from them.
 *
 * Nothing in the package may ever emit a raw license key — not logs, not
 * exception messages, not command output, not HTTP responses, not session
 * flash. This class is the single place that produces the *safe* form of a
 * key. Two primitives are offered:
 *
 *  - {@see self::mask()} — a display-safe representation that reveals ONLY the
 *    last four characters, and only for keys long enough (>= 16 chars) that
 *    the prefix cannot be reconstructed. No prefix/leading characters are ever
 *    shown. Short, empty, or null keys are fully withheld.
 *  - {@see self::fingerprint()} — a short, one-way, per-install HMAC for
 *    correlating events across log lines without ever exposing the key.
 *
 * The raw key must never be reconstructed or derived from either output.
 */
final class LicenseKeyRedactor
{
    /** Placeholder used when a value is wholly withheld. */
    public const REDACTED = '[redacted]';

    /** Minimum length before the trailing four characters may be shown. */
    private const MIN_VISIBLE_LENGTH = 16;

    /**
     * Partial mask: for a key of at least {@see self::MIN_VISIBLE_LENGTH}
     * characters, reveal ONLY the final four characters (prefixed with an
     * ellipsis). Everything shorter — and every null/empty value — is fully
     * redacted. The leading characters are never shown, and the function never
     * throws.
     */
    public static function mask(?string $key): string
    {
        if ($key === null || $key === '') {
            return self::REDACTED;
        }

        if (strlen($key) < self::MIN_VISIBLE_LENGTH) {
            return self::REDACTED;
        }

        return '…'.substr($key, -4);
    }

    /**
     * A one-way fingerprint of the key: HMAC-SHA256 keyed by the application
     * key (APP_KEY), truncated to 12 hex characters. Passing an explicit
     * secret overrides the application key (used in tests and for isolation).
     */
    public static function fingerprint(?string $key, ?string $secret = null): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $secret ??= self::appKeySecret();

        return substr(hash_hmac('sha256', $key, $secret), 0, 12);
    }

    /**
     * The application key (APP_KEY) used as the HMAC secret, so the same key
     * produces a different fingerprint on different installs (defeating
     * cross-install correlation and precomputed lookup tables). Falls back to a
     * static secret only when APP_KEY is absent.
     */
    private static function appKeySecret(): string
    {
        try {
            if (function_exists('config')) {
                $appKey = config('app.key');

                if (is_string($appKey) && $appKey !== '') {
                    return $appKey;
                }
            }
        } catch (\Throwable) {
            // fall through to the static secret
        }

        return 'corevisys-license';
    }

    private function __construct()
    {
    }
}
