<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\LogSanitizer;
use CoreVisys\License\Tests\TestCase;

/**
 * Free-form diagnostic text is the realistic key-leak vector: a server
 * validation body or a lower-level exception message can echo the raw key.
 * These tests pin the scrubber's behaviour so no message ever reaches a log
 * or a browser carrying a secret.
 */
class LogSanitizerTest extends TestCase
{
    public function test_known_secret_is_replaced(): void
    {
        $secret = 'SENTINEL-SECRET-1234';

        $scrubbed = LogSanitizer::scrubMessage("Key {$secret} was rejected.", [$secret]);

        $this->assertStringNotContainsString($secret, $scrubbed);
        $this->assertStringContainsString('[redacted]', $scrubbed);
    }

    public function test_email_addresses_are_scrubbed(): void
    {
        $scrubbed = LogSanitizer::scrubMessage('Bound to admin@example.com already.');

        $this->assertStringNotContainsString('admin@example.com', $scrubbed);
        $this->assertStringContainsString('[redacted-email]', $scrubbed);
    }

    public function test_long_opaque_tokens_are_scrubbed(): void
    {
        $token = str_repeat('A', 40);

        $scrubbed = LogSanitizer::scrubMessage("Signature {$token} did not verify.");

        $this->assertStringNotContainsString($token, $scrubbed);
        $this->assertStringContainsString('[redacted-token]', $scrubbed);
    }

    public function test_ordinary_prose_is_left_intact(): void
    {
        $message = 'The license server returned a 503 error.';

        $this->assertSame($message, LogSanitizer::scrubMessage($message));
    }

    public function test_empty_message_returns_empty_string(): void
    {
        $this->assertSame('', LogSanitizer::scrubMessage(null));
        $this->assertSame('', LogSanitizer::scrubMessage(''));
    }

    public function test_context_arrays_are_scrubbed_recursively(): void
    {
        $secret = 'SENTINEL-SECRET-1234';

        $scrubbed = LogSanitizer::scrubContext([
            'reason_code' => 'request_rejected',
            'nested' => ['error' => "the value {$secret} was invalid"],
        ], [$secret]);

        $this->assertSame('request_rejected', $scrubbed['reason_code']);
        $this->assertStringNotContainsString($secret, $scrubbed['nested']['error']);
    }

    public function test_context_safe_enum_keys_are_left_intact(): void
    {
        // Regression guard: the enum values the doctor/monitor log rely on must
        // never be token-/email-scrubbed away.
        $scrubbed = LogSanitizer::scrubContext([
            'reason_code' => 'signature_verification_failed',
            'status' => 'license_server_unavailable',
        ], []);

        $this->assertSame('signature_verification_failed', $scrubbed['reason_code']);
        $this->assertSame('license_server_unavailable', $scrubbed['status']);
    }

    public function test_explicit_secret_is_redacted_even_in_a_safe_context_key(): void
    {
        // A short key parked in a "safe" key is still a secret: an explicitly
        // supplied secret must win over the enum-preservation rule.
        $secret = 'COREVISYS-KEY-12345';

        $scrubbed = LogSanitizer::scrubContext(['reason_code' => $secret], [$secret]);

        $this->assertStringNotContainsString($secret, $scrubbed['reason_code']);
        $this->assertStringContainsString('[redacted]', $scrubbed['reason_code']);
    }

    public function test_context_secret_below_token_length_is_redacted_when_known(): void
    {
        // 19 chars: shorter than the 24-char heuristic, so only the explicit
        // known-secret path can catch it.
        $secret = 'COREVISYS-KEY-12345';

        $scrubbed = LogSanitizer::scrubContext(['error' => "the value {$secret} was rejected"], [$secret]);

        $this->assertStringNotContainsString($secret, $scrubbed['error']);
        $this->assertStringContainsString('[redacted]', $scrubbed['error']);
    }
}
