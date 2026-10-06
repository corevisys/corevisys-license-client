<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-implementation domain normalization contract test.
 *
 * The canonical specification (mirrored on server in DomainNormalizer):
 *  1. Extract host only (strip scheme, port, path, query, fragment)
 *  2. Lowercase
 *  3. Strip ONE leading www.
 *  4. Map localhost / 127.0.0.1 / ::1 → 127.0.0.1
 *
 * Every input in the dataset must produce the identical expected value
 * on both the package (FingerprintGenerator::normalizedDomain) and the
 * server (App\Support\DomainNormalizer::normalize).  The server class is
 * not available here, so we replicate the server's logic inline for the
 * cross-check assertions.
 */
class DomainNormalizationSpecTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Inline server-equivalent normalizer (mirrors DomainNormalizer::normalize)
    // -----------------------------------------------------------------------
    private function serverNormalize(?string $domain): ?string
    {
        if ($domain === null) {
            return null;
        }

        $raw = trim($domain);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '://')) {
            $parsedHost = parse_url($raw, PHP_URL_HOST);
        } elseif (str_starts_with($raw, '//')) {
            $parsedHost = parse_url('http:' . $raw, PHP_URL_HOST);
        } else {
            $parsedHost = parse_url('http://' . ltrim($raw, '/'), PHP_URL_HOST);
        }

        $host = (string) ($parsedHost ?: $raw);

        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }
        if (str_contains($host, '/')) {
            $host = explode('/', $host)[0];
        }

        $host = strtolower(trim($host));

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        if (in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
            $host = '127.0.0.1';
        }

        return $host;
    }

    // -----------------------------------------------------------------------
    // Package normalizer via FingerprintGenerator::normalizedDomain
    // -----------------------------------------------------------------------
    private function packageNormalize(string $input): string
    {
        config(['app.url' => $input]);
        $gen = new FingerprintGenerator(['strip_www' => true]);
        return $gen->normalizedDomain();
    }

    // -----------------------------------------------------------------------
    // Shared input/expected dataset
    // -----------------------------------------------------------------------
    public static function canonicalDataset(): array
    {
        return [
            ['example.com',                        'example.com'],
            ['EXAMPLE.COM',                        'example.com'],
            ['Sub.Example.COM',                    'sub.example.com'],
            ['www.example.com',                    'example.com'],
            ['WWW.EXAMPLE.COM',                    'example.com'],
            ['www.www.example.com',                'www.example.com'],
            ['mywww.example.com',                  'mywww.example.com'],
            ['http://example.com',                 'example.com'],
            ['https://example.com',                'example.com'],
            ['https://www.example.com',            'example.com'],
            ['example.com:8080',                   'example.com'],
            ['http://example.com:8080',            'example.com'],
            ['https://www.example.com/app',        'example.com'],
            ['localhost',                          '127.0.0.1'],
            ['LOCALHOST',                          '127.0.0.1'],
            ['127.0.0.1',                          '127.0.0.1'],
            ['http://localhost',                   '127.0.0.1'],
            ['http://localhost:8000',              '127.0.0.1'],
            ['http://127.0.0.1:3000/api',         '127.0.0.1'],
            ['www.localhost',                      '127.0.0.1'],
            ['sub.example.com',                   'sub.example.com'],
            ['api.example.com',                   'api.example.com'],
            ['www.sub.example.com',               'sub.example.com'],
        ];
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    #[DataProvider('canonicalDataset')]
    public function test_package_normalization_matches_canonical_spec(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->packageNormalize($input), "Package failed on: $input");
    }

    #[DataProvider('canonicalDataset')]
    public function test_server_normalization_matches_canonical_spec(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->serverNormalize($input), "Server inline failed on: $input");
    }

    #[DataProvider('canonicalDataset')]
    public function test_package_and_server_produce_identical_output(string $input, string $expected): void
    {
        $pkgResult    = $this->packageNormalize($input);
        $serverResult = $this->serverNormalize($input);

        $this->assertSame(
            $serverResult,
            $pkgResult,
            "Cross-implementation mismatch on '$input': server='$serverResult', package='$pkgResult'"
        );
    }

    public function test_exact_normalized_match_rejects_implicit_subdomains(): void
    {
        // Different subdomains must NOT collapse to the same host
        $this->assertNotSame(
            $this->packageNormalize('sub.example.com'),
            $this->packageNormalize('example.com')
        );
        $this->assertNotSame(
            $this->packageNormalize('app.example.com'),
            $this->packageNormalize('api.example.com')
        );
    }

    public function test_www_and_bare_are_identical_on_both_sides(): void
    {
        $this->assertSame(
            $this->packageNormalize('www.example.com'),
            $this->packageNormalize('example.com')
        );
        $this->assertSame(
            $this->serverNormalize('www.example.com'),
            $this->serverNormalize('example.com')
        );
    }

    public function test_localhost_variants_are_identical_on_both_sides(): void
    {
        $variants = ['localhost', 'http://localhost:8000', '127.0.0.1', 'www.localhost'];
        foreach ($variants as $v) {
            $this->assertSame(
                '127.0.0.1',
                $this->packageNormalize($v),
                "Package failed localhost mapping for: $v"
            );
            $this->assertSame(
                '127.0.0.1',
                $this->serverNormalize($v),
                "Server inline failed localhost mapping for: $v"
            );
        }
    }
}
