<?php

namespace JonHassall\ScoutBatcher;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use JonHassall\ScoutBatcher\Commands\ProcessPendingScoutUpdates;
use JonHassall\ScoutBatcher\Contracts\PendingSearchRepository;
use JonHassall\ScoutBatcher\Repositories\DatabasePendingSearchRepository;

/**
 * Registers the package services, configuration, and scheduled command.
 */
class ScoutDebouncerServiceProvider extends ServiceProvider
{
    /**
     * Register package bindings and configuration.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-batcher.php', 'scout-batcher');

        $this->app->singleton(PendingSearchRepository::class, DatabasePendingSearchRepository::class);
        $this->app->singleton(ScoutBatchProcessor::class);
    }

    /**
     * Publish assets and register the process command and scheduler.
     */
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/scout-batcher.php' => config_path('scout-batcher.php'),
        ], 'scout-batcher-config');

        $this->publishes([
            __DIR__.'/../database/migrations/2026_01_01_000000_create_scout_batcher_pending_table.php' => database_path('migrations/2026_01_01_000000_create_scout_batcher_pending_table.php'),
        ], 'scout-batcher-migrations');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessPendingScoutUpdates::class]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('scout-batcher.enabled', true)
                || ! config('scout-batcher.schedule.enabled', true)) {
                return;
            }

            $event = $schedule->command('scout-batcher:process');
            $event->withoutOverlapping()->onOneServer();
            $this->applyPollFrequency($event, (int) config('scout-batcher.schedule.poll_seconds', 300));
        });
    }

    /**
     * Apply the configured polling interval to the scheduled event.
     */
    private function applyPollFrequency(Event $event, int $seconds): void
    {
        if ($seconds >= 60) {
            $minutes = intdiv($seconds, 60);

            if ($seconds % 60 === 0) {
                $event->cron(sprintf('*/%d * * * *', $minutes));

                return;
            }
        }

        match ($seconds) {
            1 => $event->everySecond(),
            2 => $event->everyTwoSeconds(),
            5 => $event->everyFiveSeconds(),
            10 => $event->everyTenSeconds(),
            15 => $event->everyFifteenSeconds(),
            20 => $event->everyTwentySeconds(),
            30 => $event->everyThirtySeconds(),
            default => $event->everyMinute(),
        };
    }
}
