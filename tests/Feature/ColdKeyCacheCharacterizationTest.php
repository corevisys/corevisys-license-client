<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * CHARACTERIZATION TEST — known limitation, current behaviour only.
 *
 * With NO cached public-key metadata and the public-key endpoint unreachable, a
 * correctly signed server response cannot be verified locally. The verifier
 * cannot resolve the signing key, so it labels the failure 'unknown_key_id'
 * even though the real cause is a cold key cache / unreachable key endpoint.
 *
 * The label is diagnostic only: the returned status stays
 * 'signature_verification_failed' and the licence remains invalid. This test
 * locks in the labelling so a future change to the reason_code is noticed.
 */
class ColdKeyCacheCharacterizationTest extends TestCase
{
    use CapturesLogs;
    use SignsPayloads;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->captureLogs();
    }

    public function test_cold_key_cache_is_labelled_unknown_key_id_known_limitation(): void
    {
        // Deliberately NO putPublicKey / putPublicKeyMetadata for THIS key id:
        // the key cache is cold for it. A key id no other test caches is used so
        // the test is independent of suite ordering (key metadata written by an
        // earlier test must not be able to resolve it).
        Http::fake([
            '*/api/v1/license/check' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_cold',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ], 'cold-uncached-key-1')),
            '*/api/v1/license/public-key' => Http::response('', 500), // unreachable
        ]);

        $status = $this->app->make(LicenseClientInterface::class)->check(true);

        $entry = $this->findLog('signature verification failed');
        $this->assertNotNull($entry, 'Expected a signature-verification log entry.');

        // CHARACTERIZED CURRENT BEHAVIOUR: cold cache / unreachable key endpoint
        // is reported as unknown_key_id.
        $this->assertSame('unknown_key_id', $entry->context['reason_code'] ?? null);

        // The label is diagnostic only — the status and validity are unchanged.
        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);
    }
}
