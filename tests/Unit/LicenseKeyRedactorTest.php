<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\LicenseKeyRedactor;
use CoreVisys\License\Tests\TestCase;

/**
 * The redactor is the single choke point for any human-facing representation
 * of a license key. These tests pin its guarantees:
 *
 *  - mask() reveals AT MOST the trailing four characters, and only for keys of
 *    16+ characters. Shorter keys are fully withheld. No leading characters
 *    are ever shown.
 *  - fingerprint() is a one-way HMAC-SHA256 (12 hex chars) keyed by the app
 *    key, so the same key yields a stable value per install but a different
 *    value across installs (different app keys).
 */
class LicenseKeyRedactorTest extends TestCase
{
    public function test_mask_is_null_safe_and_returns_a_placeholder(): void
    {
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask(null));
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask(''));
    }

    public function test_mask_fully_redacts_short_keys(): void
    {
        // Anything below 16 chars is wholly withheld — including a 15-char key.
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask('SHORT'));
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask(str_repeat('x', 15)));
    }

    public function test_mask_reveals_only_the_last_four_characters_for_long_keys(): void
    {
        $key = 'ABCD-EFGH-IJKL-MNOP'; // 19 chars

        $masked = LicenseKeyRedactor::mask($key);

        $this->assertSame('…MNOP', $masked);
        // The leading characters must never appear.
        $this->assertStringNotContainsString('ABCD', $masked);
        $this->assertStringNotContainsString('EFGH', $masked);
        $this->assertStringNotContainsString('IJKL', $masked);
    }

    public function test_mask_at_the_minimum_length_shows_only_the_last_four(): void
    {
        $key = str_repeat('a', 12).'WXYZ'; // exactly 16 chars

        $this->assertSame('…WXYZ', LicenseKeyRedactor::mask($key));
    }

    public function test_mask_never_throws_for_arbitrary_input(): void
    {
        foreach ([null, '', ' ', "\0", str_repeat('x', 200)] as $input) {
            $this->assertIsString(LicenseKeyRedactor::mask($input));
        }
    }

    public function test_fingerprint_is_stable_for_the_same_key_and_secret(): void
    {
        $first = LicenseKeyRedactor::fingerprint('SENTINEL-KEY-0123456789', 'secret-a');
        $second = LicenseKeyRedactor::fingerprint('SENTINEL-KEY-0123456789', 'secret-a');

        $this->assertNotNull($first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $first);
        $this->assertSame($first, $second, 'Fingerprint must be deterministic for the same key and secret.');
        $this->assertStringNotContainsString('SENTINEL', $first);
    }

    public function test_fingerprint_differs_for_different_keys(): void
    {
        $this->assertNotSame(
            LicenseKeyRedactor::fingerprint('KEY-AAAAAAAAAAAAAAAA', 'secret-a'),
            LicenseKeyRedactor::fingerprint('KEY-BBBBBBBBBBBBBBBB', 'secret-a')
        );
    }

    public function test_fingerprint_differs_when_the_app_key_differs(): void
    {
        // Same key, two different HMAC secrets (stand-ins for two installs).
        $this->assertNotSame(
            LicenseKeyRedactor::fingerprint('SAME-KEY-0123456789', 'install-one'),
            LicenseKeyRedactor::fingerprint('SAME-KEY-0123456789', 'install-two')
        );
    }

    public function test_fingerprint_is_null_for_empty_or_missing_key(): void
    {
        $this->assertNull(LicenseKeyRedactor::fingerprint(null));
        $this->assertNull(LicenseKeyRedactor::fingerprint(''));
    }
}
