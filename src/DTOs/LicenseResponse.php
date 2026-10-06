<?php

namespace CoreVisys\License\DTOs;

use Carbon\Carbon;

/**
 * Raw-but-typed mapping of a CoreVisys API response envelope
 * ({success, message, data, signature, key_id}), before signature
 * verification decides whether it can be trusted.
 */
final class LicenseResponse
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $message,
        public readonly array $data,
        public readonly ?string $signature,
        public readonly ?string $keyId,
        public readonly ?string $algorithm,
        public readonly array $raw,
    ) {
    }

    public static function fromArray(array $payload): self
    {
        if (! array_key_exists('success', $payload) || ! is_bool($payload['success'])) {
            throw new \InvalidArgumentException('The license response has no boolean success field.');
        }

        if (! array_key_exists('status', $payload) || ! is_string($payload['status'])) {
            throw new \InvalidArgumentException('The license response has no status field.');
        }

        if (! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            throw new \InvalidArgumentException('The license response has no data object.');
        }

        return new self(
            success: $payload['success'],
            status: $payload['status'],
            message: $payload['message'] ?? null,
            data: $payload['data'],
            signature: array_key_exists('signature', $payload) ? $payload['signature'] : null,
            keyId: array_key_exists('key_id', $payload) ? $payload['key_id'] : null,
            algorithm: array_key_exists('algorithm', $payload) ? $payload['algorithm'] : null,
            raw: $payload,
        );
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * The exact byte-for-byte JSON that the signature was computed over.
     * CoreVisys signs the `data` object using canonical (key-sorted) JSON.
     */
    public function canonicalDataJson(): string
    {
        return json_encode(self::normalizeValue($this->data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function toLicenseStatus(bool $fromCache = false, bool $offline = false): LicenseStatus
    {
        $expiresAt = $this->get('expires_at') ? Carbon::parse($this->get('expires_at')) : null;
        $graceExpiresAt = $this->get('grace_expires_at') ? Carbon::parse($this->get('grace_expires_at')) : null;
        $serverTime = $this->get('checked_at') ?? $this->get('server_time');

        $status = $this->get('status', 'unknown');
        $isCurrent = ! $this->get('expires_at') || Carbon::parse($this->get('expires_at'))->isFuture();

        return new LicenseStatus(
            valid: $this->success && $status === 'active' && ($isCurrent || (bool) $this->get('is_grace_period', false)),
            status: $status,
            licenseId: $this->get('license_id'),
            licenseType: $this->get('license_type'),
            productCode: $this->get('product_code'),
            boundDomain: $this->get('bound_domain'),
            expiresAt: $expiresAt,
            graceExpiresAt: $graceExpiresAt,
            features: $this->get('features', []),
            serverTime: $serverTime ? Carbon::parse($serverTime) : null,
            issuedAt: $this->get('issued_at') ? Carbon::parse($this->get('issued_at')) : null,
            offlineValidUntil: $this->get('offline_valid_until') ? Carbon::parse($this->get('offline_valid_until')) : null,
            isGracePeriod: (bool) $this->get('is_grace_period', false),
            checkedAt: Carbon::now(),
            fromCache: $fromCache,
            offline: $offline,
            keyId: $this->keyId,
            signature: $this->signature,
        );
    }

    protected static function normalizeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalizeValue'], $value);
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalizeValue($item);
        }
        ksort($normalized);

        return $normalized;
    }
}
