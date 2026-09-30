<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Phase 4C Part 2: {@see SignedPayloadVerifier::verifyLocally()} is the
 * network-free verification primitive the license fast path relies on. It must
 * prove a signature from LOCAL key material only (never calling the server) and
 * fail closed without throwing when it cannot.
 */
class SignedPayloadVerifierLocalTest extends TestCase
{
    use SignsPayloads;

    private function verifier(): SignedPayloadVerifier
    {
        return new SignedPayloadVerifier(
            config: ['algorithm' => 'rsa', 'timestamp_tolerance' => 300, 'client_version' => '1.0.0'],
            storage: $this->app->make(LicenseStorageInterface::class),
            serverUrl: 'https://license.test',
            apiVersion: 'v1',
        );
    }

    private function data(): array
    {
        return [
            'license_id' => 'lic_local',
            'status' => 'active',
            'product_code' => 'test-product',
            'expires_at' => now()->addYear()->toIso8601String(),
            'offline_valid_until' => now()->addDays(7)->toIso8601String(),
        ];
    }

    private function seedKeys(string $keyId = 'test-key-1'): void
    {
        /** @var LicenseStorageInterface $storage */
        $storage = $this->app->make(LicenseStorageInterface::class);
        $storage->putPublicKey($keyId, $this->keyPair()['public'], 86400);
        $storage->putPublicKeyMetadata([
            'available_keys' => [['key_id' => $keyId, 'public_key' => $this->keyPair()['public']]],
            'revoked_key_ids' => [],
        ], 86400);
    }

    public function test_local_verification_succeeds_with_cached_key_and_no_network(): void
    {
        $this->seedKeys();

        $response = LicenseResponse::fromArray($this->signedEnvelope($this->data()));

        $this->assertTrue($this->verifier()->verifyLocally($response));

        // Local verification must never touch the network.
        Http::assertNothingSent();
    }

    public function test_local_verification_fails_closed_without_a_signature(): void
    {
        $this->seedKeys();

        $envelope = $this->signedEnvelope($this->data());
        unset($envelope['signature']);

        $response = LicenseResponse::fromArray($envelope);

        // No throw, just false — the fast path treats this as "due".
        $this->assertFalse($this->verifier()->verifyLocally($response));

        Http::assertNothingSent();
    }

    public function test_local_verification_fails_closed_for_an_unknown_key_and_never_calls_the_server(): void
    {
        // Only "test-key-1" is known locally; the response claims another key.
        $this->seedKeys();

        $response = LicenseResponse::fromArray($this->signedEnvelope($this->data(), keyId: 'unknown-key'));

        $this->assertFalse($this->verifier()->verifyLocally($response));

        // Crucially, verifyLocally() does NOT fall back to fetching the key set.
        Http::assertNothingSent();
    }
}
