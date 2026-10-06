<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Services\FingerprintGenerator;
use CoreVisys\License\Tests\TestCase;

class FingerprintGeneratorTest extends TestCase
{
    public function test_fingerprint_is_deterministic(): void
    {
        $generator = new FingerprintGenerator([
            'algorithm' => 'sha256',
            'include_domain' => true,
            'include_ip' => true,
            'strip_www' => true,
        ]);

        $this->assertSame($generator->generate(), $generator->generate());
    }

    public function test_www_is_stripped_from_domain(): void
    {
        config(['app.url' => 'https://www.example.com']);

        $generator = new FingerprintGenerator([
            'strip_www' => true,
        ]);

        $this->assertSame('example.com', $generator->normalizedDomain());
    }

    public function test_domain_normalization_lowercases_and_trims_protocol(): void
    {
        config(['app.url' => 'HTTPS://Example.COM/']);

        $generator = new FingerprintGenerator(['strip_www' => true]);

        $this->assertSame('example.com', $generator->normalizedDomain());
    }
}
