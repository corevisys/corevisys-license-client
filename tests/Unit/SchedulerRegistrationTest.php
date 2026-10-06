<?php

namespace CoreVisys\License\Tests\Unit;

use CoreVisys\License\Tests\TestCase;
use Illuminate\Console\Scheduling\Schedule;

class SchedulerRegistrationTest extends TestCase
{
    public function test_license_check_is_registered_on_the_schedule_when_auto_check_enabled(): void
    {
        config(['corevisys-license.auto_check' => true]);

        // Re-trigger the provider's booted() callback path by resolving the
        // schedule fresh and re-running boot logic against it.
        $this->app->forgetInstance(Schedule::class);
        $schedule = $this->app->make(Schedule::class);

        $provider = new \CoreVisys\License\CoreVisysServiceProvider($this->app);
        $reflection = new \ReflectionMethod($provider, 'scheduleLicenseCheck');
        $reflection->setAccessible(true);
        $reflection->invoke($provider);

        $events = collect($schedule->events())->map(fn ($e) => $e->command ?? $e->description ?? '');

        $this->assertTrue(
            $events->contains(fn ($cmd) => str_contains((string) $cmd, 'corevisys:license:check')),
            'Expected corevisys:license:check to be registered on the schedule.'
        );
    }

    public function test_license_check_is_not_registered_when_auto_check_disabled(): void
    {
        config(['corevisys-license.auto_check' => false]);

        $this->app->forgetInstance(Schedule::class);
        $schedule = $this->app->make(Schedule::class);

        $provider = new \CoreVisys\License\CoreVisysServiceProvider($this->app);
        $reflection = new \ReflectionMethod($provider, 'scheduleLicenseCheck');
        $reflection->setAccessible(true);
        $reflection->invoke($provider);

        $events = collect($schedule->events())->map(fn ($e) => $e->command ?? $e->description ?? '');

        $this->assertFalse($events->contains(fn ($cmd) => str_contains((string) $cmd, 'corevisys:license:check')));
    }
}
