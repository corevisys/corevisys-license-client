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
 *  - {@see self::mask()} — a display-safe, partially-masked representation
 *    (e.g. "ABCD…WXYZ") for human-facing output where a little context helps.
 *  - {@see self::fingerprint()} — a short, one-way, per-install hash for
 *    correlating events across log lines without ever exposing the key.
 *
 * The raw key must never be reconstructed or derived from either output.
 */
final class LicenseKeyRedactor
{
    /** Placeholder used when a value is wholly withheld. */
    public const REDACTED = '[redacted]';

    /** Minimum length before any prefix/suffix is shown (shorter keys are fully masked). */
    private const MIN_VISIBLE_LENGTH = 12;

    /**
     * Partial mask: the first and last four characters of a long key, or a
     * full redaction for short/empty keys. Never reveals enough of the key to
     * be usable.
     */
    public static function mask(?string $key): string
    {
        if ($key === null || $key === '') {
            return self::REDACTED;
        }

        if (strlen($key) < self::MIN_VISIBLE_LENGTH) {
            return self::REDACTED;
        }

        return substr($key, 0, 4).'…'.substr($key, -4);
    }

    public static function fingerprint(?string $key, ?string $salt = null): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        $salt ??= self::installSalt();

        return substr(hash_hmac('sha256', $key, $salt), 0, 16);
    }

    /**
     * A per-install salt so the same key produces a different fingerprint on
     * different installs (defeating cross-install correlation and precomputed
     * lookup tables). Falls back to a static salt only when APP_KEY is absent.
     */
    private static function installSalt(): string
    {
        try {
            if (function_exists('config')) {
                $appKey = config('app.key');

                if (is_string($appKey) && $appKey !== '') {
                    return 'corevisys-license:'.$appKey;
                }
            }
        } catch (\Throwable) {
            // fall through to the static salt
        }

        return 'corevisys-license';
    }

    private function __construct()
    {
    }
}
