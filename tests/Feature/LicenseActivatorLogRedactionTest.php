<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Exceptions\LicenseClientException;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseActivator;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;

/**
 * The activation failure path must never persist or log the submitted key,
 * even when a transport/validation layer echoes a SHORT key (below the
 * sanitizer's 24-char token heuristic) back in its exception message. The key
 * is passed as an explicit known secret, so it is stripped from both the
 * stored last_error_message and the structured log context.
 */
class LicenseActivatorLogRedactionTest extends TestCase
{
    use CapturesLogs;

    public function test_activation_failure_never_stores_or_logs_the_submitted_key(): void
    {
        $key = 'COREVISYS-KEY-12345'; // 19 chars: below the token-length heuristic.

        $config = config('corevisys-license');
        $storage = $this->app->make(LicenseStorageInterface::class);
        $verifier = new SignedPayloadVerifier($config, $storage, 'https://license.test', 'v1');
        $fingerprint = $this->app->make(FingerprintGenerator::class);

        // A handler whose exception message echoes the submitted key, modelling
        // any current/future transport path that leaks it.
        $api = new class('https://license.test', 'v1', $config) extends ApiRequestHandler {
            public function post(string $endpoint, array $body): LicenseResponse
            {
                throw new LicenseClientException(
                    'License '.$body['license_key'].' was rejected by the server.',
                    'request_rejected',
                    422,
                    false
                );
            }
        };

        $activator = new LicenseActivator($api, $verifier, $fingerprint, $storage, 'test-product');

        $this->captureLogs();

        $result = $activator->activate($key);

        $this->assertFalse($result->success);

        $entry = $this->findLog('activation failed');
        $this->assertNotNull($entry);
        $this->assertStringNotContainsString($key, json_encode($entry->context));

        $record = $storage->get('test-product');
        $this->assertIsArray($record);
        $this->assertStringNotContainsString($key, (string) ($record['last_error_message'] ?? ''));
    }
}
