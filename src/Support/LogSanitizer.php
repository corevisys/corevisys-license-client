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
     * Enum context keys: their values are fixed machine codes, not free-form
     * text. The length-based token scrub is skipped for a well-formed value so
     * the operator signal survives.
     */
    private const ENUM_CONTEXT_KEYS = [
        'reason_code',
        'previous_reason_code',
        'status',
    ];

    /**
     * Identifier context keys: published / server-issued identifiers. As with
     * the enum keys, the token scrub is skipped only for a well-formed value
     * (a UUID is 36 chars but must not be token-scrubbed).
     */
    private const IDENTIFIER_CONTEXT_KEYS = [
        'product_code',
        'key_id',
        'license_id',
        'license_type',
    ];

    /** A bare lowercase enum token, at most 64 chars. */
    private const ENUM_VALUE_PATTERN = '/^[a-z][a-z_]*$/';

    /** A published identifier / UUID: letters, digits, dot, colon, hyphen. */
    private const IDENTIFIER_VALUE_PATTERN = '/^[A-Za-z0-9_.:\-]{1,64}$/';

    /** Longest value any structured context key may carry. */
    private const STRUCTURED_VALUE_MAX_LENGTH = 64;

    /**
     * @param  array<int, string|null>  $knownSecrets  Raw values guaranteed to be sensitive.
     */
    public static function scrubMessage(?string $message, array $knownSecrets = []): string
    {
        if ($message === null || $message === '') {
            return '';
        }

        $message = self::scrubKnownSecrets($message, $knownSecrets);
        $message = self::scrubEmails($message);
        $message = self::scrubTokens($message);

        return $message;
    }

    /**
     * Replace only explicitly supplied secrets (no heuristic email/token
     * scrubbing). Shared by scrubMessage() and the structured "safe" context
     * keys, whose own values may look token-ish but must still yield to a
     * caller-known secret.
     *
     * @param  array<int, string|null>  $knownSecrets
     */
    private static function scrubKnownSecrets(string $value, array $knownSecrets): string
    {
        foreach ($knownSecrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 8) {
                $value = str_replace($secret, LicenseKeyRedactor::REDACTED, $value);
            }
        }

        return $value;
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
            if (is_string($value)) {
                $keyName = (string) $key;
                $isStructuredKey = in_array($keyName, self::ENUM_CONTEXT_KEYS, true)
                    || in_array($keyName, self::IDENTIFIER_CONTEXT_KEYS, true);

                if ($isStructuredKey) {
                    if (self::isWellFormedStructuredValue($keyName, $value)) {
                        // A well-formed enum / identifier keeps its exact value —
                        // the operator signal the doctor / monitor log relies on —
                        // but an explicitly supplied secret and an echoed email
                        // are still removed.
                        $context[$key] = self::scrubEmails(self::scrubKnownSecrets($value, $knownSecrets));
                    } else {
                        // Anything else under a structured key is not a legitimate
                        // enum / identifier; scrub it fully rather than trust it.
                        $context[$key] = LicenseKeyRedactor::REDACTED;
                    }

                    continue;
                }

                $context[$key] = self::scrubMessage($value, $knownSecrets);
            } elseif (is_array($value)) {
                $context[$key] = self::scrubContext($value, $knownSecrets);
            }
        }

        return $context;
    }

    /**
     * Whether a value is a well-formed enum / identifier for its context key —
     * the only case where the token scrub may be skipped.
     */
    private static function isWellFormedStructuredValue(string $key, string $value): bool
    {
        if (strlen($value) > self::STRUCTURED_VALUE_MAX_LENGTH) {
            return false;
        }

        if (in_array($key, self::ENUM_CONTEXT_KEYS, true)) {
            return preg_match(self::ENUM_VALUE_PATTERN, $value) === 1;
        }

        if (in_array($key, self::IDENTIFIER_CONTEXT_KEYS, true)) {
            return preg_match(self::IDENTIFIER_VALUE_PATTERN, $value) === 1;
        }

        return false;
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
