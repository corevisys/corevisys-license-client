<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use Illuminate\Console\Command;

class LicenseDeactivateCommand extends Command
{
    protected $signature = 'corevisys:license:deactivate {--force : Skip the confirmation prompt}';

    protected $description = 'Deactivate this installation and clear the local license cache.';

    public function handle(LicenseClientInterface $license): int
    {
        if (! $this->option('force') && ! $this->confirm('This will deactivate the license on this domain and clear the local cache. Continue?')) {
            $this->components->warn('Aborted.');

            return self::SUCCESS;
        }

        $success = $license->deactivate();

        if ($success) {
            $this->components->info('License deactivated and local cache cleared.');
        } else {
            $this->components->warn('Could not confirm deactivation with the server (the server binding may remain), but the local cache has been cleared.');
        }

        return self::SUCCESS;
    }
}
