<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Facades\CoreVisysLicense;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Support\LicenseKeyRedactor;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live round-trip integration test against the real CoreVisys license server.
 * Executes: activate -> check -> pulse -> fast-path trust.
 *
 * This test performs REAL network calls and therefore lives in the `live`
 * group, which is excluded from the default suite in phpunit.xml. It is
 * SKIPPED unless BOTH environment variables are present:
 *
 *   COREVISYS_TEST_LICENSE_KEY   a real, disposable test license key
 *   COREVISYS_TEST_SERVER_URL    the CoreVisys license server base URL
 *
 * No license key is ever committed to the repository.
 */
#[Group('live')]
class LiveServerRoundTripTest extends TestCase
{
    private const PRODUCT = 'corevisys';

    private ?string $liveKey = null;

    private ?string $serverUrl = null;

    protected function setUp(): void
    {
        parent::setUp();

        $key = getenv('COREVISYS_TEST_LICENSE_KEY');
        $serverUrl = getenv('COREVISYS_TEST_SERVER_URL');

        if (! is_string($key) || $key === '' || ! is_string($serverUrl) || $serverUrl === '') {
            $this->markTestSkipped(
                'Set COREVISYS_TEST_LICENSE_KEY and COREVISYS_TEST_SERVER_URL to run the live round-trip test.'
            );
        }

        $this->liveKey = $key;
        $this->serverUrl = $serverUrl;

        // This test intentionally performs real HTTP calls; opt out of the
        // suite-wide stray-request guard installed by the base TestCase.
        Http::allowStrayRequests();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('corevisys-license.server_url', (string) getenv('COREVISYS_TEST_SERVER_URL'));
        $app['config']->set('corevisys-license.product_code', self::PRODUCT);
        $app['config']->set('corevisys-license.license_key', (string) getenv('COREVISYS_TEST_LICENSE_KEY'));
        $app['config']->set('corevisys-license.verify_ssl', true);
        $app['config']->set('corevisys-license.cache_driver', 'database');
        $app['config']->set('corevisys-license.cache_fallback_store', null);
        $app['config']->set('corevisys-license.retry.times', 1);
    }

    public function test_live_server_activate_check_pulse_round_trip(): void
    {
        $liveKey = (string) $this->liveKey;

        // Bind fingerprint generator to match the active bound fingerprint for this license key
        $boundFingerprint = hash('sha256', 'test-fingerprint');
        $mockFingerprint = Mockery::mock(FingerprintGenerator::class)->makePartial();
        $mockFingerprint->shouldReceive('normalizedDomain')->andReturn('localhost');
        $mockFingerprint->shouldReceive('generate')->andReturn($boundFingerprint);
        $this->app->instance(FingerprintGenerator::class, $mockFingerprint);

        // ---------------------------------------------------------------------
        // 1. ACTIVATE
        // ---------------------------------------------------------------------
        $activationResult = CoreVisysLicense::activate($liveKey);

        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $lastError = $storage->get(self::PRODUCT)['last_error_message'] ?? 'none';

        $this->assertTrue(
            $activationResult->success,
            'Live activation failed: ' . ($activationResult->message ?? 'unknown') . ' | Internal error: ' . $lastError
        );
        $this->assertNotNull($activationResult->status, 'Expected activation status DTO.');
        $this->assertTrue($activationResult->status->isActive(), 'Expected license status to be active.');
        $this->assertSame(self::PRODUCT, $activationResult->status->productCode);
        $this->assertNotNull($activationResult->status->licenseId);

        // ---------------------------------------------------------------------
        // 2. CHECK
        // ---------------------------------------------------------------------
        $checkStatus = CoreVisysLicense::check();

        $this->assertTrue(CoreVisysLicense::isValid(), 'Expected CoreVisysLicense::isValid() to be true.');
        $this->assertTrue(CoreVisysLicense::isActive(), 'Expected CoreVisysLicense::isActive() to be true.');
        $this->assertSame('active', $checkStatus->status);
        $this->assertNotNull($checkStatus->licenseId);
        $this->assertNotNull($checkStatus->offlineValidUntil);

        // ---------------------------------------------------------------------
        // 3. PULSE
        // ---------------------------------------------------------------------
        $pulseStatus = CoreVisysLicense::pulse();

        $this->assertNotNull($pulseStatus, 'Pulse returned null.');
        $this->assertSame('active', $pulseStatus->status);
        $this->assertNotNull($pulseStatus->offlineValidUntil);

        // ---------------------------------------------------------------------
        // 4. PERSISTED SIGNED PAYLOAD & PUBLIC KEY
        // ---------------------------------------------------------------------
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $stored = $storage->get(self::PRODUCT);

        $this->assertNotNull($stored);
        $this->assertSame('active', $stored['status']);
        $this->assertNotEmpty($stored['key_id']);
        $this->assertNotEmpty($stored['signature']);
        $this->assertNotEmpty($stored['signed_payload']);

        // Public key was retrieved from server and cached locally
        $publicKey = $storage->getPublicKey($stored['key_id']);
        $this->assertNotNull($publicKey, 'Expected public key to be cached in storage.');

        // ---------------------------------------------------------------------
        // 5. FAST-PATH TRUST
        // ---------------------------------------------------------------------
        $this->assertTrue(CoreVisysLicense::isValid(), 'Fast-path verification should pass.');

        // ---------------------------------------------------------------------
        // 6. REDACTION & LOG INTEGRITY
        // ---------------------------------------------------------------------
        $masked = LicenseKeyRedactor::mask($liveKey);
        $this->assertSame('…'.substr($liveKey, -4), $masked);
        $this->assertStringNotContainsString($liveKey, $masked);

        // ---------------------------------------------------------------------
        // 7. ARTISAN COMMANDS (STATUS & DOCTOR)
        // ---------------------------------------------------------------------
        $this->artisan('corevisys:license:status')
            ->assertSuccessful()
            ->expectsOutputToContain('active');

        $this->artisan('corevisys:license:doctor')
            ->assertSuccessful();

        // ---------------------------------------------------------------------
        // 8. OFFLINE GRACE VERIFICATION (Server down -> uses real signed record)
        // ---------------------------------------------------------------------
        Http::fake([
            $this->serverUrl . '/*' => Http::response([], 500),
        ]);

        $offlineStatus = CoreVisysLicense::check();
        $this->assertTrue(CoreVisysLicense::isValid(), 'License must remain valid under offline grace using real signed payload.');
        $this->assertSame('active', $offlineStatus->status);
    }
}
