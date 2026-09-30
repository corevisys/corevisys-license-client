<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Services\LicenseStorage;
use CoreVisys\License\Tests\TestCase;
use Illuminate\Support\Facades\DB;

class LicenseStorageTest extends TestCase
{
    public function test_license_key_is_encrypted_at_rest(): void
    {
        $storage = new LicenseStorage(config('corevisys-license'));

        $storage->put('test-product', [
            'license_key' => 'PLAINTEXT-KEY-9999',
            'status' => 'active',
        ]);

        $row = DB::table('corevisys_license_cache')->where('product_code', 'test-product')->first();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('PLAINTEXT-KEY-9999', $row->encrypted_license_key);

        $decrypted = $storage->decryptLicenseKey((array) $row);
        $this->assertSame('PLAINTEXT-KEY-9999', $decrypted);
    }

    public function test_forget_clears_cached_record(): void
    {
        $storage = new LicenseStorage(config('corevisys-license'));
        $storage->put('test-product', ['status' => 'active']);

        $this->assertNotNull($storage->get('test-product'));

        $storage->forget('test-product');

        $this->assertNull($storage->get('test-product'));
    }

    // ---------------------------------------------------------------------
    // Fallback store resolution (Phase 3 follow-up)
    //
    // The collision check must compare against the *resolved* primary store
    // (explicit cache_store OR the application default), never only the raw
    // cache_store. These cases pin that behaviour at the protected boundary
    // that the public fallback chain depends on.
    // ---------------------------------------------------------------------

    /**
     * A LicenseStorage probe exposing the protected fallback-store resolver,
     * with the "was the env set explicitly?" signal forced deterministically so
     * the packaged-default tolerance can be tested without touching real env.
     */
    private function fallbackNameFor(array $config, bool $explicitlySet = false): ?string
    {
        $probe = new class($config, $explicitlySet) extends LicenseStorage
        {
            private bool $explicit;

            public function __construct(array $config, bool $explicit)
            {
                parent::__construct($config);
                $this->explicit = $explicit;
            }

            public function probeFallbackName(): ?string
            {
                return $this->fallbackStoreName();
            }

            protected function hasExplicitFallbackSetting(): bool
            {
                return $this->explicit;
            }
        };

        return $probe->probeFallbackName();
    }

    public function test_cache_mode_fallback_equal_to_explicit_primary_store_is_dropped(): void
    {
        config(['cache.default' => 'redis']);

        $name = $this->fallbackNameFor([
            'cache_driver' => 'cache',
            'cache_store' => 'array',
            'cache_fallback_store' => 'array',
        ], explicitlySet: true);

        $this->assertNull($name, 'A deliberately-set fallback equal to the explicit primary store must be dropped.');
    }

    public function test_cache_mode_fallback_equal_to_resolved_default_store_is_dropped(): void
    {
        // cache_store is unset, so the primary resolves to the app default. A
        // deliberately-set fallback that equals that default is a collision
        // even though the raw cache_store value is null.
        config(['cache.default' => 'redis']);

        $name = $this->fallbackNameFor([
            'cache_driver' => 'cache',
            'cache_store' => null,
            'cache_fallback_store' => 'redis',
        ], explicitlySet: true);

        $this->assertNull($name, 'A deliberately-set fallback equal to the resolved default store must be dropped.');
    }

    public function test_cache_mode_packaged_default_equal_to_app_default_is_tolerated(): void
    {
        // Out-of-the-box state: the packaged default fallback ("file") matches
        // an application whose default store is also "file". Nothing was
        // explicitly configured, so the fallback is kept usable.
        config(['cache.default' => 'file']);

        $name = $this->fallbackNameFor([
            'cache_driver' => 'cache',
            'cache_store' => null,
            'cache_fallback_store' => 'file',
        ], explicitlySet: false);

        $this->assertSame('file', $name);
    }

    public function test_cache_mode_distinct_fallback_is_kept(): void
    {
        config(['cache.default' => 'file']);

        $name = $this->fallbackNameFor([
            'cache_driver' => 'cache',
            'cache_store' => 'array',
            'cache_fallback_store' => 'redis',
        ], explicitlySet: false);

        $this->assertSame('redis', $name);
    }

    public function test_database_mode_keeps_fallback_regardless_of_primary_store(): void
    {
        // In database mode the primary is the table, never a cache store, so a
        // fallback that happens to share a name with the app cache is fine.
        config(['cache.default' => 'file']);

        $name = $this->fallbackNameFor([
            'cache_driver' => 'database',
            'cache_store' => null,
            'cache_fallback_store' => 'file',
        ], explicitlySet: true);

        $this->assertSame('file', $name);
    }

    public function test_empty_fallback_is_disabled(): void
    {
        $this->assertNull($this->fallbackNameFor([
            'cache_driver' => 'database',
            'cache_fallback_store' => '',
        ]));
    }
}
