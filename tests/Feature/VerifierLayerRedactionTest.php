<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseStorageInterface;
use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Events\LicenseCheckFailed;
use CoreVisys\License\Events\LicenseServerUnavailable;
use CoreVisys\License\Exceptions\LicenseClientException;
use CoreVisys\License\Exceptions\LicenseServerUnavailableException;
use CoreVisys\License\Services\ApiRequestHandler;
use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Services\LicenseVerifier;
use CoreVisys\License\Services\SignedPayloadVerifier;
use CoreVisys\License\Tests\Concerns\CapturesLogs;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * Defense in depth: even if a lower layer (ApiRequestHandler) fails to scrub a
 * key out of an exception message, the VERIFIER layer must not write a raw key
 * to its log, to the stored last_error_message, to a dispatched event payload,
 * or to the returned status.
 *
 * ApiRequestHandler is deliberately bypassed with a double that throws a
 * message already containing the 19-char key, so the verifier layer alone is
 * under test.
 */
class VerifierLayerRedactionTest extends TestCase
{
    use CapturesLogs;

    private const KEY = 'ABCD-EFGH-IJKL-MNOP'; // 19 chars — below the 24-char token heuristic

    /** @var array<int, object> */
    private array $dispatched = [];

    private function verifierThrowing(\Throwable $boom): LicenseVerifier
    {
        $config = config('corevisys-license');
        $config['logging']['enabled'] = true;
        $config['logging']['channel'] = 'stack';

        $storage = $this->app->make(LicenseStorageInterface::class);

        $api = new class($boom, $config) extends ApiRequestHandler {
            public function __construct(private \Throwable $boom, private array $cfg)
            {
                parent::__construct('https://license.test', 'v1', $cfg);
            }

            public function post(string $endpoint, array $body): LicenseResponse
            {
                throw $this->boom;
            }
        };

        return new LicenseVerifier(
            $api,
            new SignedPayloadVerifier($config, $storage, 'https://license.test', 'v1'),
            $this->app->make(FingerprintGenerator::class),
            $storage,
            'test-product',
            $config,
        );
    }

    private function captureEvents(): void
    {
        $this->dispatched = [];

        Event::listen(function (LicenseServerUnavailable|LicenseCheckFailed $event) {
            $this->dispatched[] = $event;
        });
    }

    private function logDump(): string
    {
        $lines = [];

        foreach ($this->capturedLogs as $event) {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        }

        return implode("\n", $lines);
    }

    private function storedError(): string
    {
        $record = $this->app->make(LicenseStorageInterface::class)->get('test-product');

        return (string) ($record['last_error_message'] ?? '');
    }

    private function eventDump(): string
    {
        return json_encode(array_map(fn ($e) => $e->meta, $this->dispatched));
    }

    public function test_server_unavailable_exception_message_key_is_redacted_at_the_verifier_layer(): void
    {
        $boom = new LicenseServerUnavailableException(
            'License key '.self::KEY.' could not be verified (server unavailable).'
        );

        $this->captureLogs();
        $this->captureEvents();
        $status = $this->verifierThrowing($boom)->check(self::KEY, true);

        $this->assertNotEmpty($this->logDump(), 'Expected the verifier to log so the leak check is meaningful.');
        $this->assertStringNotContainsString(self::KEY, $this->logDump());
        $this->assertStringNotContainsString(self::KEY, $this->storedError());
        $this->assertStringNotContainsString(self::KEY, $this->eventDump());
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($status));
    }

    public function test_client_exception_message_key_is_redacted_at_the_verifier_layer(): void
    {
        $boom = new LicenseClientException(
            'License key '.self::KEY.' is not valid for this product.',
            'request_rejected',
            422,
            false,
        );

        $this->captureLogs();
        $this->captureEvents();
        $status = $this->verifierThrowing($boom)->check(self::KEY, true);

        $this->assertNotEmpty($this->logDump(), 'Expected the verifier to log so the leak check is meaningful.');
        $this->assertStringNotContainsString(self::KEY, $this->logDump());
        $this->assertStringNotContainsString(self::KEY, $this->storedError());
        $this->assertStringNotContainsString(self::KEY, $this->eventDump());
        $this->assertStringNotContainsString(self::KEY, (string) json_encode($status));
    }
}
