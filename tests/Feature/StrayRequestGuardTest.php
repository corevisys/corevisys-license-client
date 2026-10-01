<?php

namespace CoreVisys\License\Tests\Feature;

use CoreVisys\License\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The shared TestCase installs Http::preventStrayRequests() in setUp(), so a
 * test can never silently reach the real network: any HTTP call that is not
 * matched by an explicit Http::fake() fails loudly. This is what keeps the
 * suite hermetic (and is why the `live` group must opt out via
 * Http::allowStrayRequests()).
 *
 * The guard's exception class lives in different namespaces across Laravel 10,
 * 11 and 12 (Illuminate\Http\Client\StrayRequestException vs the older
 * Illuminate\Http\Client\Exceptions\StrayRequestException), so this test
 * matches on the class name rather than importing a specific FQCN.
 */
class StrayRequestGuardTest extends TestCase
{
    public function test_unfaked_request_is_blocked_by_the_stray_request_guard(): void
    {
        try {
            // No Http::fake() for this host — the guard must reject it.
            Http::get('https://license.test/api/v1/license/check');

            $this->fail('Expected the stray-request guard to reject an unfaked HTTP call.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'StrayRequestException',
                get_class($e),
                'A stray HTTP call must fail with a StrayRequestException, got: '.get_class($e)
            );

            // Guard failure must not be mistakable for an offline/server-down
            // condition, which is a ConnectionException the licence flow treats
            // as a transient trigger for the cached fallback path.
            $this->assertNotInstanceOf(
                ConnectionException::class,
                $e,
                'A stray-request guard failure must never look like an offline ConnectionException.'
            );
        }
    }

    public function test_explicitly_faked_request_is_allowed(): void
    {
        Http::fake([
            'https://license.test/api/v1/license/check' => Http::response(['ok' => true], 200),
        ]);

        $response = Http::get('https://license.test/api/v1/license/check');

        $this->assertSame(200, $response->status());
        $this->assertTrue($response->json('ok'));
    }
}
