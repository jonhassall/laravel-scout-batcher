<?php

namespace JonHassall\ScoutBatcher\Tests;

use Carbon\CarbonImmutable;
use Laravel\Scout\ScoutServiceProvider;
use JonHassall\ScoutBatcher\ScoutDebouncerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [ScoutServiceProvider::class, ScoutDebouncerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('scout.driver', 'null');
        $app['config']->set('scout.queue', false);
        $app['config']->set('scout-batcher.schedule.enabled', false);
        $app['config']->set('scout-batcher.debounce_seconds', 5);
        $app['config']->set('scout-batcher.max_batch_size', 3);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }
}
