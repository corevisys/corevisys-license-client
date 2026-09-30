<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Tests\Concerns\SignsPayloads;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The raw license key must never leave the trust boundary through any
 * operator- or browser-facing surface: logs, exception/result messages,
 * session flash, old input, or a re-rendered form. A distinctive sentinel
 * value is used so any leak is unambiguous.
 */
class LicenseKeyNonDisclosureTest extends TestCase
{
    use SignsPayloads;

    private const SENTINEL = 'SENTINEL-WEB-KEY-9f3a2b7c';

    /**
     * Capture every log record emitted while the callback runs, flattened to
     * "level message context" so a leak anywhere is detectable.
     */
    private function captureLogs(callable $callback): string
    {
        $lines = [];

        Log::listen(function ($event) use (&$lines) {
            $lines[] = $event->level.' '.$event->message.' '.json_encode($event->context);
        });

        $callback();

        return implode("\n", $lines);
    }

    public function test_successful_activation_never_logs_the_raw_key(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response($this->signedEnvelope([
                'license_id' => 'lic_nd',
                'status' => 'active',
                'product_code' => 'test-product',
                'expires_at' => now()->addYear()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
            ])),
        ]);

        $result = null;
        $logs = $this->captureLogs(function () use (&$result) {
            $result = $this->app->make(LicenseClientInterface::class)->activate(self::SENTINEL);
        });

        $this->assertTrue($result->success);
        $this->assertStringNotContainsString(self::SENTINEL, $logs);
    }

    public function test_failed_activation_logs_no_raw_key_and_returns_a_generic_message(): void
    {
        // The server echoes the key back in its validation body — the client
        // must scrub it everywhere it is used.
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response(['message' => 'License key '.self::SENTINEL.' not found.'], 404),
        ]);

        $result = null;
        $logs = $this->captureLogs(function () use (&$result) {
            $result = $this->app->make(LicenseClientInterface::class)->activate(self::SENTINEL);
        });

        $this->assertNotEmpty($logs, 'Expected the failure path to log, so the leak check is meaningful.');
        $this->assertFalse($result->success);
        $this->assertStringNotContainsString(self::SENTINEL, $logs);
        $this->assertSame('Activation failed. Please check the key and try again.', $result->message);
    }

    public function test_server_error_never_surfaces_the_raw_key_in_logs(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'boom'], 500),
        ]);

        $logs = $this->captureLogs(function () {
            $this->app->make(LicenseClientInterface::class)->activate(self::SENTINEL);
        });

        $this->assertStringNotContainsString(self::SENTINEL, $logs);
    }

    public function test_submitted_key_is_not_echoed_back_after_a_failed_activation(): void
    {
        Http::fake([
            '*/api/v1/license/public-key' => Http::response($this->publicKeyResponse()),
            '*/api/v1/license/activate' => Http::response(['message' => 'License key '.self::SENTINEL.' is invalid.'], 422),
            '*/api/v1/license/check' => Http::response(['message' => 'not activated'], 404),
        ]);

        $response = $this->followingRedirects()
            ->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => self::SENTINEL]);

        $response->assertOk();

        $html = $response->getContent();
        $this->assertStringNotContainsString(self::SENTINEL, $html, 'The raw key was echoed back into the page.');
        $this->assertStringNotContainsString('value="'.self::SENTINEL.'"', $html);

        // The user sees only a generic error, never the server's key-bearing text.
        $this->assertStringContainsString('Activation failed. Please check the key and try again.', $html);
    }

    public function test_session_flash_never_contains_the_raw_key(): void
    {
        Http::fake([
            '*/api/v1/license/activate' => Http::response(['message' => 'License key '.self::SENTINEL.' is invalid.'], 404),
        ]);

        $this->from(route('corevisys.license.activate'))
            ->post(route('corevisys.license.activate'), ['license_key' => self::SENTINEL]);

        $sessionDump = json_encode(session()->all());
        $this->assertStringNotContainsString(self::SENTINEL, (string) $sessionDump);
    }

    public function test_activation_form_uses_password_input_and_never_prefills_the_key(): void
    {
        Http::fake();

        $html = $this->get(route('corevisys.license.activate'))->getContent();

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('autocomplete="new-password"', $html);
        $this->assertStringNotContainsString('old(\'license_key\')', $html);
    }
}
