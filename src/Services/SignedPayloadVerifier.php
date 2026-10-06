<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Exceptions\SignatureVerificationException;
use CoreVisys\License\Support\LogSanitizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Verifies that a LicenseResponse was genuinely signed by CoreVisys, using
 * the server's published public key. Fails closed: any missing signature,
 * unresolvable key, algorithm mismatch, or bad signature is rejected.
 */
class SignedPayloadVerifier
{
    public function __construct(
        protected array $config,
        protected LicenseStorageInterface $storage,
        protected string $serverUrl,
        protected string $apiVersion,
    ) {
        self::validateConfiguration($this->config['algorithm'] ?? 'rsa');
    }

    public static function validateConfiguration(string $algorithm): void
    {
        if (strtolower(trim($algorithm)) !== 'rsa') {
            throw new \InvalidArgumentException("Unsupported CoreVisys license signature algorithm: {$algorithm}. Supported algorithm: rsa.");
        }
    }

    public static function normalizeAlgorithm(string $algorithm): string
    {
        return match (strtolower(trim($algorithm))) {
            'rsa', 'rsa-sha256' => 'rsa',
            default => throw new \InvalidArgumentException("Unsupported server signature algorithm: {$algorithm}. Supported algorithm: RSA-SHA256."),
        };
    }

    /**
     * @throws SignatureVerificationException
     */
    public function verify(LicenseResponse $response): void
    {
        $this->verifyResponse($response, allowNetwork: true);
    }

    /**
     * Verify a response using ONLY locally cached key material.
     *
     * Unlike {@see self::verify()}, this method never performs a network call
     * to refresh the published key set — it resolves the key purely from the
     * local store. It is used by the license verifier's fast path, which must
     * decide whether a cached record can be trusted without reaching (or even
     * being able to reach) the license server.
     *
     * Returns false (never throws) when the signature cannot be proven from
     * local material — a cold key cache, an unknown/revoked key_id, a missing
     * signature, or a bad signature — so the caller can safely treat the
     * record as due and fall through to the normal online path.
     */
    public function verifyLocally(LicenseResponse $response): bool
    {
        try {
            $this->verifyResponse($response, allowNetwork: false);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        } catch (\Throwable) {
            // Never let an unexpected verification error escape the fast path:
            // an unverifiable record is simply treated as due.
            return false;
        }
    }

    /**
     * @throws SignatureVerificationException
     */
    protected function verifyResponse(LicenseResponse $response, bool $allowNetwork): void
    {
        if (empty($response->signature)) {
            throw new SignatureVerificationException('The server response was not signed.');
        }

        if (empty($response->keyId)) {
            throw new SignatureVerificationException('The server response is missing a key identifier.');
        }

        self::validateConfiguration($this->config['algorithm'] ?? 'rsa');
        $clientAlgorithm = 'rsa';
        if ($response->algorithm !== null) {
            try {
                $serverAlgorithm = self::normalizeAlgorithm($response->algorithm);
            } catch (\InvalidArgumentException) {
                throw new SignatureVerificationException(sprintf(
                    'algorithm mismatch: server declared %s, client configured %s',
                    $response->algorithm,
                    $this->config['algorithm'] ?? 'rsa'
                ));
            }

            if ($serverAlgorithm !== $clientAlgorithm) {
                throw new SignatureVerificationException(sprintf(
                    'algorithm mismatch: server declared %s, client configured %s',
                    $response->algorithm,
                    $this->config['algorithm'] ?? 'rsa'
                ));
            }
        }

        $publicKey = $this->resolvePublicKey($response->keyId, $allowNetwork);

        if (! $publicKey) {
            throw new SignatureVerificationException('Unable to resolve the public key needed to verify this response.');
        }

        $canonicalJson = $response->canonicalDataJson();
        $signature = base64_decode($response->signature, true);

        if ($signature === false) {
            throw new SignatureVerificationException('The response signature is not validly encoded.');
        }

        $verified = $this->verifyRsa($canonicalJson, $signature, $publicKey);

        if (! $verified) {
            throw new SignatureVerificationException('The license response signature is invalid.');
        }

        $this->assertFreshTimestamp($response);
    }

    protected function verifyRsa(string $data, string $signature, string $publicKeyPem): bool
    {
        $publicKeyPem = $this->normalizeKeyMaterial($publicKeyPem);
        $key = openssl_pkey_get_public($publicKeyPem);

        if ($key === false) {
            return false;
        }

        $result = openssl_verify($data, $signature, $key, OPENSSL_ALGO_SHA256);

        return $result === 1;
    }

    protected function normalizeKeyMaterial(string $keyMaterial): string
    {
        if (str_contains($keyMaterial, 'BEGIN ')) {
            return $keyMaterial;
        }

        $decoded = base64_decode($keyMaterial, true);

        return $decoded !== false ? $decoded : $keyMaterial;
    }

    protected function assertFreshTimestamp(LicenseResponse $response): void
    {
        $timestamp = $response->get('checked_at') ?? $response->get('server_time');

        if (! $timestamp) {
            return;
        }

        $tolerance = $this->config['timestamp_tolerance'] ?? 300;

        try {
            $serverTime = \Carbon\Carbon::parse($timestamp);
        } catch (\Throwable) {
            throw new SignatureVerificationException('The response timestamp could not be parsed.');
        }

        if (abs(now()->diffInSeconds($serverTime, false)) > $tolerance) {
            throw new SignatureVerificationException('The response timestamp is outside the allowed tolerance (possible replay).');
        }
    }

    protected function resolvePublicKey(string $keyId, bool $allowNetwork = true): ?string
    {
        $metadata = ($allowNetwork ? $this->refreshKeyMetadata() : null)
            ?? $this->storage->getPublicKeyMetadata();

        if (! $metadata) {
            return null;
        }

        if (in_array($keyId, $metadata['revoked_key_ids'], true)) {
            throw new SignatureVerificationException('The signing key has been revoked.');
        }

        if (! in_array($keyId, array_column($metadata['available_keys'], 'key_id'), true)) {
            return null;
        }

        $cached = $this->storage->getPublicKey($keyId);

        if ($cached) {
            return $cached;
        }

        foreach ($metadata['available_keys'] as $availableKey) {
            if ($availableKey['key_id'] === $keyId) {
                return $availableKey['public_key'];
            }
        }

        return null;
    }

    protected function refreshKeyMetadata(): ?array
    {
        $metadata = $this->fetchPublicKeyFromServer();

        if ($metadata === null) {
            return null;
        }

        $ttl = $this->config['public_key_cache_ttl'] ?? 86400;
        $this->storage->putPublicKeyMetadata($metadata, $ttl);

        foreach ($metadata['available_keys'] as $availableKey) {
            $this->storage->putPublicKey($availableKey['key_id'], $availableKey['public_key'], $ttl);
        }

        return $metadata;
    }

    /**
    * @return array{available_keys: array<int, array{key_id: string, public_key: string}>, revoked_key_ids: array<int, string>}
     */
    protected function fetchPublicKeyFromServer(): ?array
    {
        try {
            $response = Http::timeout(config('corevisys-license.connection_timeout', 10))
                ->withOptions(['verify' => config('corevisys-license.verify_ssl', true)])
                ->withHeaders(['X-API-Version' => config('corevisys-license.client_version', '1.0.0')])
                ->get(rtrim($this->serverUrl, '/')."/api/{$this->apiVersion}/license/public-key");

            if (! $response->successful()) {
                return null;
            }

            $body = $response->json();
            $pem = $body['public_key'] ?? $body['data']['public_key'] ?? null;
            $keyId = $body['key_id'] ?? $body['data']['key_id'] ?? null;

            if (! $pem || ! $keyId) {
                return null;
            }

            $availableKeys = $body['available_keys'] ?? $body['data']['available_keys'] ?? [];
            if (! is_array($availableKeys) || $availableKeys === []) {
                $availableKeys = [['key_id' => $keyId, 'public_key' => $pem]];
            }

            return [
                'available_keys' => array_values(array_filter($availableKeys, fn ($key) => is_array($key) && ! empty($key['key_id']) && ! empty($key['public_key']))),
                'revoked_key_ids' => array_values(array_filter($body['revoked_key_ids'] ?? $body['data']['revoked_key_ids'] ?? [], 'is_string')),
            ];
        } catch (\Throwable $e) {
            if (config('corevisys-license.logging.enabled', true)) {
                Log::channel(config('corevisys-license.logging.channel', 'stack'))->warning(
                    'CoreVisys license: failed to fetch public key.',
                    LogSanitizer::scrubContext(['error' => $e->getMessage()])
                );
            }

            return null;
        }
    }
}
