<?php

namespace CoreVisys\License\Support;

/**
 * Scrubs secrets out of free-form text before it is ever written to a log,
 * an exception message, or any user-facing surface.
 *
 * Interpolated server messages and lower-level exception messages (Laravel's
 * own HTTP client, the database layer, validation bag dumps) are the realistic
 * leak vectors: a request body or a bound query parameter can carry the raw
 * license key or a request payload. This class removes:
 *
 *  - any explicitly supplied secret (passed by the caller that knows the key),
 *  - email addresses (server-side validation error bodies echo them),
 *  - long opaque tokens (license keys and base64 signatures).
 *
 * It is deliberately conservative: it only touches runs of characters that no
 * ordinary diagnostic message legitimately needs.
 */
final class LogSanitizer
{
    /**
     * Anything at least this long, made only of base64/hex/key-ish characters,
     * is assumed to be a secret token and replaced wholesale.
     */
    private const TOKEN_MIN_LENGTH = 24;

    /**
     * Context keys whose string values are structured identifiers / enum codes,
     * not free-form text, and must never be token-scrubbed. Each is either a
     * published identifier (key_id), a server-issued id (license_id), a public
     * product identifier (product_code), or a fixed enum (status, reason_code).
     * None is the raw license key.
     */
    private const SAFE_CONTEXT_KEYS = [
        'reason_code',
        'previous_reason_code',
        'status',
        'product_code',
        'key_id',
        'license_id',
        'license_type',
    ];

    /**
     * @param  array<int, string|null>  $knownSecrets  Raw values guaranteed to be sensitive.
     */
    public static function scrubMessage(?string $message, array $knownSecrets = []): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        foreach ($knownSecrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 8) {
                $message = str_replace($secret, LicenseKeyRedactor::REDACTED, $message);
            }
        }

        $message = self::scrubEmails($message);
        $message = self::scrubTokens($message);

        return $message;
    }

    /**
     * Recursively scrub every string value in a log context array. Array keys
     * are left untouched. Non-string scalars and nested arrays are preserved.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, string|null>  $knownSecrets
     * @return array<string, mixed>
     */
    public static function scrubContext(array $context, array $knownSecrets = []): array
    {
        foreach ($context as $key => $value) {
            // Structured identifier / enum fields are never secrets, and their
            // values (e.g. "signature_verification_failed",
            // "license_server_unavailable") look token-ish, so token-scrubbing
            // them would erase the very operator signal we need.
            if (is_string($value) && in_array((string) $key, self::SAFE_CONTEXT_KEYS, true)) {
                continue;
            }

            if (is_string($value)) {
                $context[$key] = self::scrubMessage($value, $knownSecrets);
            } elseif (is_array($value)) {
                $context[$key] = self::scrubContext($value, $knownSecrets);
            }
        }

        return $context;
    }

    private static function scrubEmails(string $message): string
    {
        $result = preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
            '[redacted-email]',
            $message
        );

        return $result ?? $message;
    }

    private static function scrubTokens(string $message): string
    {
        $pattern = '/[A-Za-z0-9+\/=\-_]{'.self::TOKEN_MIN_LENGTH.',}/';

        $result = preg_replace($pattern, '[redacted-token]', $message);

        return $result ?? $message;
    }

    private function __construct()
    {
    }
}
