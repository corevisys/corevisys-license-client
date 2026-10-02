<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\DTOs\LicenseResponse;
use CoreVisys\License\Tests\TestCase;

class LicenseResponseBoundDomainTest extends TestCase
{
    public function test_to_license_status_maps_bound_domain_from_payload_data(): void
    {
        $payload = [
            'success' => true,
            'status'  => 'active',
            'data'    => [
                'status'       => 'active',
                'license_id'   => 'lic-12345',
                'product_code' => 'corevisys-pro',
                'license_type' => 'commercial',
                'bound_domain' => 'client.example.com',
                'expires_at'   => null,
                'features'     => ['audit', 'export'],
            ],
        ];

        $response = LicenseResponse::fromArray($payload);
        $status = $response->toLicenseStatus();

        $this->assertSame('client.example.com', $status->boundDomain);
        $this->assertSame('client.example.com', $response->get('bound_domain'));
        $this->assertTrue($status->valid);
        $this->assertTrue($status->isActive());
    }

    public function test_to_license_status_handles_null_bound_domain(): void
    {
        $payload = [
            'success' => true,
            'status'  => 'active',
            'data'    => [
                'status'       => 'active',
                'license_id'   => 'lic-12345',
                'product_code' => 'corevisys-pro',
                'bound_domain' => null,
            ],
        ];

        $response = LicenseResponse::fromArray($payload);
        $status = $response->toLicenseStatus();

        $this->assertNull($status->boundDomain);
        $this->assertNull($response->get('bound_domain'));
    }

    public function test_to_license_status_handles_omitted_bound_domain(): void
    {
        $payload = [
            'success' => true,
            'status'  => 'active',
            'data'    => [
                'status'       => 'active',
                'license_id'   => 'lic-12345',
                'product_code' => 'corevisys-pro',
            ],
        ];

        $response = LicenseResponse::fromArray($payload);
        $status = $response->toLicenseStatus();

        $this->assertNull($status->boundDomain);
        $this->assertNull($response->get('bound_domain'));
    }
}
