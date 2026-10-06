<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Events\LicenseCheckFailed;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The REAL SignedPayloadVerifier (no exception mock) must make check() log the
 * distinguishing reason_code for a revoked vs an unknown key_id, while the
 * returned status and the dispatched event stay generic
 * 'signature_verification_failed' (Section A).
 *
 * The exception messages the real verifier throws are:
 *   - revoked: "The signing key has been revoked."              (contains 'revoked')
 *   - unknown: "Unable to resolve the public key needed to verify this response." (contains 'public key')
 * and signatureFailureReason() maps those to revoked_key_id / unknown_key_id.
 */
class VerifierKeyIdReasonCodeTest extends TestCase
{
    use CapturesLogs;
    use SignsPayloads;

    private const KEY = 'ABCD-EFGH-IJKL-MNOP';

    /** @var array<int, object> */
    private array $dispatched = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        $this->captureLogs();
    }

    private function verifier(): LicenseVerifier
    {
        $config = config('corevisys-license');
        $config['logging']['enabled'] = true;
        $config['logging']['channel'] = 'stack';

        $storage = $this->app->make(LicenseStorageInterface::class);

        return new LicenseVerifier(
            new ApiRequestHandler('https://license.test', 'v1', $config),
            new SignedPayloadVerifier($config, $storage, 'https://license.test', 'v1'),
            $this->app->make(FingerprintGenerator::class),
            $storage,
            'test-product',
            $config,
        );
    }

    private function envelope(): array
    {
        return $this->signedEnvelope([
            'license_id' => 'lic_reason',
            'status' => 'active',
            'product_code' => 'test-product',
            'expires_at' => now()->addYear()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
        ], 'test-key-1');
    }

    private function captureEvents(): void
    {
        $this->dispatched = [];

        Event::listen(function (LicenseCheckFailed $event) {
            $this->dispatched[] = $event;
        });
    }

    public function test_revoked_key_id_logs_revoked_reason_code_on_the_real_verifier(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response([
                'public_key' => $this->keyPair()['public'],
                'key_id' => 'test-key-1',
                'revoked_key_ids' => ['test-key-1'],
            ]),
            '*/api/v1/license/check' => Http::response($this->envelope()),
        ]);

        $this->captureEvents();
        $status = $this->verifier()->check(self::KEY, true);

        $entry = $this->findLog('signature verification failed');
        $this->assertNotNull($entry, 'Expected a signature-verification log entry.');
        $this->assertSame('error', $entry->level);
        $this->assertSame('revoked_key_id', $entry->context['reason_code'] ?? null);

        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);
        $this->assertSame('signature_verification_failed', $this->dispatched[0]->meta['reason'] ?? null);
    }

    public function test_unknown_key_id_logs_unknown_reason_code_on_the_real_verifier(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response([
                'public_key' => $this->keyPair()['public'],
                'key_id' => 'some-other-key',
            ]),
            '*/api/v1/license/check' => Http::response($this->envelope()),
        ]);

        $this->captureEvents();
        $status = $this->verifier()->check(self::KEY, true);

        $entry = $this->findLog('signature verification failed');
        $this->assertNotNull($entry, 'Expected a signature-verification log entry.');
        $this->assertSame('error', $entry->level);
        $this->assertSame('unknown_key_id', $entry->context['reason_code'] ?? null);

        $this->assertFalse($status->valid);
        $this->assertSame('signature_verification_failed', $status->status);
        $this->assertSame('signature_verification_failed', $this->dispatched[0]->meta['reason'] ?? null);
    }
}
