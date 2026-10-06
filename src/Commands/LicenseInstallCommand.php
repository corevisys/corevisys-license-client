<?php

namespace CoreVisys\License\Commands;

use Illuminate\Console\Command;

class LicenseInstallCommand extends Command
{
    protected $signature = 'corevisys:license:install';

    protected $description = 'Publish the CoreVisys license config and migration, and print .env setup guidance.';

    public function handle(): int
    {
        $this->components->info('Installing CoreVisys License Client...');

        $this->call('vendor:publish', [
            '--tag' => 'corevisys-license-config',
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'corevisys-license-migrations',
        ]);

        $this->newLine();
        $this->components->info('Add the following to your .env file:');
        $this->newLine();

        $this->line(<<<'ENV'
            # COREVISYS_LICENSE_SERVER_URL=https://license.corevisys.com   # default — only set this if you run your own license server
            COREVISYS_PRODUCT_CODE=my-product
            COREVISYS_LICENSE_KEY=XXXX-XXXX-XXXX-XXXX
            COREVISYS_LICENSE_API_VERSION=v1
            COREVISYS_LICENSE_TIMEOUT=10
            COREVISYS_LICENSE_VERIFY_SSL=true
            COREVISYS_LICENSE_AUTO_ACTIVATE=false
            COREVISYS_LICENSE_AUTO_CHECK=true
            COREVISYS_LICENSE_CHECK_INTERVAL=86400
            COREVISYS_LICENSE_GRACE_PERIOD=72
            COREVISYS_LICENSE_ALLOW_OFFLINE=true
            ENV);

        $this->newLine();
        $this->components->warn('Run `php artisan migrate` next, then `php artisan corevisys:license:activate`.');

        return self::SUCCESS;
    }
}
