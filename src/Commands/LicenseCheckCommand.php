<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Services\LicenseNotifier;
use Illuminate\Console\Command;

class LicenseCheckCommand extends Command
{
    protected $signature = 'corevisys:license:check {--force : Bypass the check_interval and force an online check}';

    protected $description = 'Check the current license status against the CoreVisys license server.';

    public function handle(LicenseClientInterface $license, LicenseNotifier $notifier): int
    {
        $status = $license->check((bool) $this->option('force'));

        $this->table(
            ['Valid', 'Status', 'Type', 'Domain', 'Expires At', 'From Cache', 'Offline'],
            [[
                $status->valid ? 'yes' : 'no',
                $status->status,
                $status->licenseType ?? '—',
                $status->boundDomain ?? '—',
                $status->expiresAt?->toDateTimeString() ?? 'never',
                $status->fromCache ? 'yes' : 'no',
                $status->offline ? 'yes' : 'no',
            ]]
        );

        if (! $status->valid) {
            // Single notification call site: the health check (this command,
            // scheduled by the package). The notifier is throttled and is a
            // no-op unless notifications.enabled is true.
            $notifier->notifyFailure($status, $status->status ?: 'license_invalid');

            $this->components->error('License is not currently valid.');

            return self::FAILURE;
        }

        $notifier->recordRecovery();

        $this->components->info('License is valid.');

        return self::SUCCESS;
    }
}
