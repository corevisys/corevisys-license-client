<?php

namespace CoreVisys\License\Services;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\ActivationResult;
use CoreVisys\License\Events\LicenseActivated;
use CoreVisys\License\Exceptions\LicenseClientException;
use CoreVisys\License\Support\LogSanitizer;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/**
 * Handles the one-time (or re-)activation handshake against
 * POST /license/activate.
 */
class LicenseActivator
{
    public function __construct(
        protected ApiRequestHandler $api,
        protected SignedPayloadVerifier $signatureVerifier,
        protected FingerprintGenerator $fingerprint,
        protected LicenseStorageInterface $storage,
        protected string $productCode,
    ) {
    }

    public function activate(string $licenseKey): ActivationResult
    {
        $fingerprint = $this->fingerprint->generate();

        try {
            $response = $this->api->post('license/activate', [
                'license_key' => $licenseKey,
                'product_code' => $this->productCode,
                'domain' => $this->fingerprint->normalizedDomain(),
                'ip' => request()?->ip() ?? '127.0.0.1',
                'fingerprint' => $fingerprint,
                'app_url' => config('app.url'),
                'package_version' => $this->packageVersion(),
                'laravel_version' => app()->version(),
                'php_version' => PHP_VERSION,
            ]);

            $this->signatureVerifier->verify($response);

            $status = $response->toLicenseStatus();

            if (! $response->success || ! $status->isActive()) {
                return ActivationResult::failure(
                    $this->safeServerMessage($response->message, $licenseKey) ?? 'Activation was rejected by the server.',
                    'activation_rejected'
                );
            }

            // SEC-007: Reject cross-product activation if response productCode is missing or does not match configured productCode
            if (empty($status->productCode) || $status->productCode !== $this->productCode) {
                $this->log('warning', 'CoreVisys license: product code mismatch on activation.', [
                    'reason_code' => 'product_code_mismatch',
                    'response_product' => $status->productCode,
                    'configured_product' => $this->productCode,
                ], [$licenseKey]);

                return ActivationResult::failure(
                    "License product code ({$status->productCode}) does not match application product code ({$this->productCode}).",
                    'product_code_mismatch'
                );
            }

            $this->storage->put($this->productCode, [
                'license_id' => $status->licenseId,
                'license_key' => $licenseKey,
                'status' => $status->status,
                'license_type' => $status->licenseType,
                'bound_domain' => $status->boundDomain,
                'fingerprint_hash' => $fingerprint,
                'expires_at' => $status->expiresAt,
                'grace_expires_at' => $status->graceExpiresAt,
                'issued_at' => $status->issuedAt,
                'offline_valid_until' => $status->offlineValidUntil,
                'is_grace_period' => $status->isGracePeriod,
                'features' => $status->features,
                'signed_payload' => $response->canonicalDataJson(),
                'signature' => $response->signature,
                'key_id' => $response->keyId,
                'last_successful_check_at' => now(),
                'next_check_at' => now()->addSeconds((int) config('corevisys-license.check_interval', 86400)),
                'last_error_at' => null,
                'last_error_message' => null,
            ]);

            // Structured success log (context only — never the raw key).
            $this->log('info', 'CoreVisys license: activated successfully.', [
                'license_id' => $status->licenseId,
                'product_code' => $this->productCode,
                'key_id' => $response->keyId,
                'reason_code' => 'license_activated',
            ]);

            Event::dispatch(new LicenseActivated($status));

            return ActivationResult::success($this->safeServerMessage($response->message, $licenseKey) ?? 'License activated successfully.', $status);
        } catch (LicenseClientException $e) {
            // Diagnostic detail is logged (redacted) but the value returned to
            // callers is a generic, key-free message. The submitted key is
            // passed as a known secret so even a short key echoed back by a
            // lower layer (a server validation body, a transport exception) is
            // stripped from BOTH the stored error and the log context.
            $knownSecret = array_filter([$licenseKey]);
            $safeMessage = LogSanitizer::scrubMessage($e->getMessage(), $knownSecret);

            $this->storage->put($this->productCode, [
                'last_error_at' => now(),
                'last_error_message' => $safeMessage,
            ]);

            $this->log('warning', 'CoreVisys license: activation failed.', [
                'reason_code' => $e->errorCode(),
                'product_code' => $this->productCode,
                'error' => $e->getMessage(),
            ], $knownSecret);

            return ActivationResult::failure(
                'Activation failed. Please check the key and try again.',
                $e->errorCode()
            );
        }
    }

    /**
     * Scrub a server-supplied envelope message before it can reach any caller
     * (command output, controller flash, or any ActivationResult consumer).
     * The server may echo the submitted key back in its validation text, so
     * the key is passed as a known secret; long opaque tokens and email
     * addresses are removed as well. Returns null for null/empty input so the
     * caller's generic fallback message applies.
     */
    protected function safeServerMessage(?string $message, ?string $knownSecret = null): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }

        return LogSanitizer::scrubMessage($message, array_filter([$knownSecret]));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, string|null>  $knownSecrets
     */
    protected function log(string $level, string $message, array $context = [], array $knownSecrets = []): void
    {
        if (! config('corevisys-license.logging.enabled', true)) {
            return;
        }

        Log::channel(config('corevisys-license.logging.channel', 'stack'))->{$level}(
            LogSanitizer::scrubMessage($message, $knownSecrets),
            LogSanitizer::scrubContext($context, $knownSecrets)
        );
    }

    /**
     * Notify the license server that this installation is being deactivated.
     *
     * Error handling policy (mirrors the scope in FIX-004):
     *
     *  - 404: The license is not found on the server (already deactivated or
     *         key was rotated). Treat as success so the local cache is always
     *         cleared — the server has nothing to deactivate.
     *  - 403: Server rejected the deactivation (domain/fingerprint mismatch or
     *         the server-side authorisation check failed). Return false so the
     *         caller knows the server rejected it (but local cache is still
     *         cleared by LicenseClient).
     *  - Network / 5xx: Server unreachable. Return false (soft-fail). The local
     *         cache is always cleared by LicenseClient regardless.
     *
     * @param  string  $licenseKey  Plain-text license key.
     */
    public function deactivate(string $licenseKey): bool
    {
        $fingerprint = $this->fingerprint->generate();

        try {
            $response = $this->api->post('license/deactivate', [
                'license_key'  => $licenseKey,
                'product_code' => $this->productCode,
                'domain'       => $this->fingerprint->normalizedDomain(),
                'ip'           => request()?->ip() ?? '127.0.0.1',
                'fingerprint'  => $fingerprint,
                'reason'       => 'application_removed',
            ]);

            $success = (bool) $response->success;

            $this->log('info', 'CoreVisys license: deactivation request sent.', [
                'product_code' => $this->productCode,
                'success'      => $success,
                'reason_code'  => $success ? 'deactivated' : 'server_rejected',
            ], [$licenseKey]);

            return $success;
        } catch (LicenseClientException $e) {
            // 404 → already gone on the server side; treat as success
            if ($e->httpStatus() === 404) {
                $this->log('info', 'CoreVisys license: deactivate returned 404 — treating as already deactivated.', [
                    'product_code' => $this->productCode,
                    'reason_code'  => 'not_found_on_server',
                ], [$licenseKey]);

                return true;
            }

            // Any other error (network, 403, 5xx) → soft-fail; caller clears cache
            $this->log('warning', 'CoreVisys license: deactivation failed — server could not be reached or rejected the request.', [
                'reason_code'  => $e->errorCode(),
                'product_code' => $this->productCode,
                'error'        => $e->getMessage(),
            ], [$licenseKey]);

            return false;
        }
    }

    protected function packageVersion(): string
    {
        return '1.0.0';
    }
}
