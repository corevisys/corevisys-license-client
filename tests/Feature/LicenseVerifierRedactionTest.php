<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * The verifier path (check()) must never surface the submitted key when the
 * server is unavailable or rejects the request with a body that echoes the key.
 * The key travels only in the request body; any lower-layer echo must be
 * redacted before it reaches a log, the stored last_error_message, or the
 * returned LicenseStatus.
 */
class LicenseVerifierRedactionTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'ABCD-EFGH-IJKL-MNOP'; // 19 chars — below the 24-char token heuristic

    private function verifier(): LicenseVerifier
    {
        $config = config('corevisys-license');
        $config['logging']['enabled'] = true;
        $config['logging']['channel'] = 'stack';
        $config['retry'] = ['times' => 1, 'base_delay_ms' => 1, 'max_delay_ms' => 1];

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

    /**
     * @param  array<int, \Illuminate\Log\Events\MessageLogged>  $logs
     */
    private function logDump(array $logs): string
    {
        $lines = [];

        foreach ($logs as $event) {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        }

        return implode("\n", $lines);
    }

    public function test_unavailable_server_never_surfaces_the_key(): void
    {
        Http::fake(['*/api/v1/license/check' => Http::response('', 500)]);

        $this->captureLogs();
        $status = $this->verifier()->check(self::KEY, true);

        $this->assertStringNotContainsString(self::KEY, $this->logDump($this->capturedLogs));

        $record = $this->app->make(LicenseStorageInterface::class)->get('test-product');
        $this->assertStringNotContainsString(self::KEY, (string) ($record['last_error_message'] ?? ''));

        $this->assertStringNotContainsString(self::KEY, (string) json_encode($status));
    }

    public function test_rejected_response_echoing_the_key_is_redacted_everywhere(): void
    {
        Http::fake([
            '*/api/v1/license/check' => Http::response(
                ['message' => 'License key '.self::KEY.' is not valid for this product.'],
                422
            ),
        ]);

        $this->captureLogs();
        $status = $this->verifier()->check(self::KEY, true);

        $this->assertStringNotContainsString(self::KEY, $this->logDump($this->capturedLogs));

        $record = $this->app->make(LicenseStorageInterface::class)->get('test-product');
        $this->assertStringNotContainsString(self::KEY, (string) ($record['last_error_message'] ?? ''));

        $this->assertStringNotContainsString(self::KEY, (string) json_encode($status));
    }
}
