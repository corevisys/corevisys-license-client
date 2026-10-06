<?php

namespace CoreVisys\License\Facades;

use Carbon\Carbon;
use CoreVisys\License\DTOs\ActivationResult;
use CoreVisys\License\DTOs\LicenseStatus;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ActivationResult activate(?string $licenseKey = null)
 * @method static LicenseStatus check(bool $force = false)
 * @method static \CoreVisys\License\DTOs\LicenseStatus|null pulse()
 * @method static bool isValid()
 * @method static bool isActive()
 * @method static bool isExpired()
 * @method static bool isRevoked()
 * @method static bool isSuspended()
 * @method static Carbon|null expiresAt()
 * @method static int|null daysRemaining()
 * @method static bool feature(string $feature)
 * @method static array features()
 * @method static bool deactivate()
 * @method static void clearCache()
 * @method static LicenseStatus|null status()
 * @method static string fingerprint()
 * @method static string|null licenseKey()
 *
 * @see \CoreVisys\License\Services\LicenseClient
 */
class CoreVisysLicense extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \CoreVisys\License\Contracts\LicenseClientInterface::class;
    }
}
