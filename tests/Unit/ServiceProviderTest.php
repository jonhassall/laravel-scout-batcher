<?php

namespace LaravelScoutDebouncer\Tests\Unit;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use LaravelScoutDebouncer\ScoutDebouncerServiceProvider;
use LaravelScoutDebouncer\Tests\TestCase;

class ServiceProviderTest extends TestCase
{
    public function test_scheduled_process_is_singleton_and_non_overlapping(): void
    {
        config()->set('scout-batcher.schedule.enabled', true);

        $schedule = app(Schedule::class);
        $events = collect($schedule->events())
            ->filter(fn (Event $event): bool => str_contains($event->command ?? '', 'scout-batcher:process'));

        $this->assertCount(1, $events);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertTrue($events->first()->onOneServer);
    }

    public function test_it_supports_standard_minute_based_poll_intervals(): void
    {
        $provider = new ScoutDebouncerServiceProvider($this->app);
        $schedule = new Schedule();
        $event = $schedule->command('scout-batcher:process');

        $method = new \ReflectionMethod($provider, 'applyPollFrequency');
        $method->setAccessible(true);
        $method->invoke($provider, $event, 300);

        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
