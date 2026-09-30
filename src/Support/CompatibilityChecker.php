<?php

namespace CoreVisys\License\Support;

/**
 * Pure, side-effect-free compatibility checks for the CoreVisys license
 * client. Returns a structured list of {name, status, message} rows and is
 * shared by the boot-time config-version warning and the read-only doctor
 * command.
 *
 * It only reads configuration and version inputs; it performs no I/O, does
 * not mutate state, and never emits a value that could be secret — required
 * env keys are reported by name and presence only.
 */
final class CompatibilityChecker
{
    /** The config schema version this package expects. */
    public const EXPECTED_CONFIG_VERSION = 1;

    /** Minimum supported PHP version (mirrors composer.json "php": "^8.2"). */
    public const MINIMUM_PHP_VERSION = '8.2';

    /** Supported Laravel major versions (via testbench 8/9/10). */
    public const SUPPORTED_LARAVEL_MAJORS = [10, 11, 12];

    private string $phpVersion;

    private string $laravelVersion;

    /**
     * @param  array<string, mixed>  $config  The resolved `corevisys-license` config array.
     * @param  string|null  $phpVersion  Injected for tests; defaults to PHP_VERSION.
     * @param  string|null  $laravelVersion  Injected for tests; defaults to the running framework.
     */
    public function __construct(
        private array $config,
        ?string $phpVersion = null,
        ?string $laravelVersion = null,
    ) {
        $this->phpVersion = $phpVersion ?? PHP_VERSION;
        $this->laravelVersion = $laravelVersion ?? self::detectLaravelVersion();
    }

    /**
     * Run every check and return the structured result rows.
     *
     * @return array<int, array{name: string, status: string, message: string}>
     */
    public function run(): array
    {
        return array_merge(
            [
                $this->checkPhpVersion(),
                $this->checkLaravelVersion(),
                $this->checkConfigVersion(),
            ],
            $this->checkRequiredEnvKeys(),
        );
    }

    /**
     * True when no check returned a `fail` status (warnings are tolerated).
     */
    public function passes(): bool
    {
        foreach ($this->run() as $result) {
            if ($result['status'] === 'fail') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function checkPhpVersion(): array
    {
        $supported = version_compare($this->phpVersion, self::MINIMUM_PHP_VERSION, '>=');

        return [
            'name' => 'php_version',
            'status' => $supported ? 'pass' : 'fail',
            'message' => $supported
                ? "PHP {$this->phpVersion} satisfies the >= ".self::MINIMUM_PHP_VERSION.' requirement.'
                : "PHP {$this->phpVersion} is below the required ".self::MINIMUM_PHP_VERSION.'.',
        ];
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function checkLaravelVersion(): array
    {
        $major = self::laravelMajor($this->laravelVersion);
        $supported = $major !== null && in_array($major, self::SUPPORTED_LARAVEL_MAJORS, true);
        $supportedList = implode(', ', self::SUPPORTED_LARAVEL_MAJORS);

        return [
            'name' => 'laravel_version',
            'status' => $supported ? 'pass' : 'fail',
            'message' => $supported
                ? "Laravel {$this->laravelVersion} is within the supported majors ({$supportedList})."
                : "Laravel {$this->laravelVersion} is outside the supported majors ({$supportedList}).",
        ];
    }

    /**
     * @return array{name: string, status: string, message: string}
     */
    private function checkConfigVersion(): array
    {
        $expected = self::EXPECTED_CONFIG_VERSION;
        $actual = $this->config['config_version'] ?? null;

        if ($actual === null) {
            return [
                'name' => 'config_version',
                'status' => 'warn',
                'message' => "config_version is missing; expected {$expected}.",
            ];
        }

        if ((int) $actual !== $expected) {
            return [
                'name' => 'config_version',
                'status' => 'warn',
                'message' => "config_version {$actual} does not match the expected {$expected}.",
            ];
        }

        return [
            'name' => 'config_version',
            'status' => 'pass',
            'message' => "config_version {$expected} matches the expected value.",
        ];
    }

    /**
     * Checks each required env key by name, using the mapped config key as the
     * presence signal. Never returns the underlying value.
     *
     * @return array<int, array{name: string, status: string, message: string}>
     */
    private function checkRequiredEnvKeys(): array
    {
        $required = $this->config['required_env_keys'] ?? [];

        if (! is_array($required)) {
            return [];
        }

        $results = [];

        foreach ($required as $envKey => $configKey) {
            $present = ! self::isBlank($this->config[$configKey] ?? null);

            $results[] = [
                'name' => 'env_key:'.$envKey,
                'status' => $present ? 'pass' : 'fail',
                'message' => $present
                    ? "Required env key {$envKey} is present."
                    : "Required env key {$envKey} is missing.",
            ];
        }

        return $results;
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private static function laravelMajor(string $version): ?int
    {
        if (preg_match('/^(\d+)/', trim($version), $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private static function detectLaravelVersion(): string
    {
        if (function_exists('app')) {
            try {
                $app = app();

                if (is_object($app) && method_exists($app, 'version')) {
                    return (string) $app->version();
                }
            } catch (\Throwable) {
                // Fall through to the framework constant.
            }
        }

        if (class_exists(\Illuminate\Foundation\Application::class)) {
            return \Illuminate\Foundation\Application::VERSION;
        }

        return '0.0.0';
    }
}
