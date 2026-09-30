<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Support\LicenseKeyRedactor;
use CoreVisys\License\Tests\TestCase;

/**
 * The redactor is the single choke point for any human-facing representation
 * of a license key. These tests pin the two safety guarantees: short/empty
 * keys are fully withheld, and the mask never reveals enough of a long key to
 * be reconstructed into a usable credential.
 */
class LicenseKeyRedactorTest extends TestCase
{
    public function test_long_key_is_masked_to_prefix_and_suffix(): void
    {
        $this->assertSame('ABCD…MNOP', LicenseKeyRedactor::mask('ABCD-EFGH-IJKL-MNOP'));
    }

    public function test_short_key_is_fully_redacted(): void
    {
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask('SHORT-KEY1'));
    }

    public function test_empty_and_null_are_redacted(): void
    {
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask(''));
        $this->assertSame(LicenseKeyRedactor::REDACTED, LicenseKeyRedactor::mask(null));
    }

    public function test_exactly_minimum_length_key_is_masked(): void
    {
        $key = str_repeat('x', 12);

        $this->assertSame('xxxx…xxxx', LicenseKeyRedactor::mask($key));
    }

    public function test_fingerprint_is_short_deterministic_and_key_free(): void
    {
        $key = 'SENTINEL-KEY-0123456789';

        $first = LicenseKeyRedactor::fingerprint($key);
        $second = LicenseKeyRedactor::fingerprint($key);

        $this->assertNotNull($first);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $first);
        $this->assertSame($first, $second, 'Fingerprint must be deterministic for the same key.');
        $this->assertStringNotContainsString('SENTINEL', $first);
        $this->assertStringNotContainsString($key, $first);
    }

    public function test_fingerprint_is_null_for_empty_or_missing_key(): void
    {
        $this->assertNull(LicenseKeyRedactor::fingerprint(null));
        $this->assertNull(LicenseKeyRedactor::fingerprint(''));
    }
}
