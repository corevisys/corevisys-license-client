<?php

namespace CoreVisys\License\Commands;

use CoreVisys\License\Contracts\LicenseClientInterface;
use CoreVisys\License\Support\LicenseKeyRedactor;
use Illuminate\Console\Command;

class LicenseActivateCommand extends Command
{
    protected $signature = 'corevisys:license:activate {key? : License key to activate (omit to be prompted securely; defaults to COREVISYS_LICENSE_KEY)}';

    protected $description = 'Activate this installation against the CoreVisys license server. Run with no arguments to be prompted for the key (recommended): passing it as an argument leaves the raw key in your shell history and process list.';

    public function handle(LicenseClientInterface $license): int
    {
        $key = $this->argument('key') ?? $this->resolveKey();

        $this->components->task('Collecting fingerprint and domain', fn () => true);

        $result = $license->activate($key);

        if (! $result->success) {
            $this->components->error($result->message ?? 'Activation failed.');

            return self::FAILURE;
        }

        $this->components->info($result->message ?? 'License activated successfully.');

        if ($result->status) {
            $this->table(
                ['Status', 'Type', 'Domain', 'Expires At', 'Key'],
                [[
                    $result->status->status,
                    $result->status->licenseType ?? '—',
                    $result->status->boundDomain ?? '—',
                    $result->status->expiresAt?->toDateTimeString() ?? 'never',
                    // Never print the raw key — only a display-safe mask.
                    LicenseKeyRedactor::mask(is_string($key) ? $key : null),
                ]]
            );
        }

        return self::SUCCESS;
    }

    /**
     * Prompt for the key without echoing it to the terminal. Returns null when
     * the command runs non-interactively (so the configured env key is used).
     */
    protected function resolveKey(): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        $answer = $this->secret('License key (input is hidden)');

        return is_string($answer) && $answer !== '' ? $answer : null;
    }
}
