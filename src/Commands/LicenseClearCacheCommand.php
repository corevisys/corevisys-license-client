<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Console\Command;

class LicenseClearCacheCommand extends Command
{
    protected $signature = 'corevisys:license:clear-cache';

    protected $description = 'Clear the locally cached license data and public key, without contacting the server.';

    public function handle(LicenseClientInterface $license): int
    {
        $license->clearCache();

        $this->components->info('Local license cache cleared.');

        return self::SUCCESS;
    }
}
