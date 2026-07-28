<?php

namespace LaravelScoutDebouncer\Tests\Unit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use LaravelScoutDebouncer\Tests\TestCase;

class ProcessPendingScoutUpdatesTest extends TestCase
{
    public function test_concurrent_process_exits_successfully_without_processing(): void
    {
        $store = app(CacheFactory::class)->store()->getStore();

        $this->assertInstanceOf(LockProvider::class, $store);

        $lock = $store->lock('scout-batcher:process', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('scout-batcher:process')
                ->expectsOutputToContain('Another Scout Batcher process is already running.')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }
    }
}
