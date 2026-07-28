<?php

namespace LaravelScoutDebouncer\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use LaravelScoutDebouncer\ScoutBatchProcessor;
use RuntimeException;

/**
 * Processes pending Scout updates from the debouncer queue.
 */
class ProcessPendingScoutUpdates extends Command
{
    protected $signature = 'scout-batcher:process
        {--force : Process under-sized batches before the debounce time elapses}
        {--max-batches= : Override the maximum batches processed by this run}';

    protected $description = 'Process pending Laravel Scout updates in driver-agnostic batches';

    /**
     * Process pending batches and report the result to the console.
     */
    public function handle(ScoutBatchProcessor $processor, CacheFactory $cache): int
    {
        $store = $cache->store(config('scout-batcher.lock_store'))->getStore();

        if (! $store instanceof LockProvider) {
            throw new RuntimeException(
                'The configured Scout Batcher cache store does not support atomic locks.'
            );
        }

        $lock = $store->lock(
            'scout-batcher:process',
            max(1, (int) config('scout-batcher.lock_seconds', 3600)),
        );

        if (! $lock->get()) {
            if (! $this->getOutput()->isQuiet()) {
                $this->components->info('Another Scout Batcher process is already running.');
            }

            return self::SUCCESS;
        }

        try {
            return $this->process($processor);
        } finally {
            $lock->release();
        }
    }

    /**
     * Process pending batches while the singleton command lock is held.
     */
    private function process(ScoutBatchProcessor $processor): int
    {
        $maxBatches = $this->option('max-batches');
        $maxBatches = $maxBatches === null ? null : max(0, (int) $maxBatches);

        $report = $processor->process($maxBatches, (bool) $this->option('force'));

        if (! $this->getOutput()->isQuiet()) {
            $this->components->info(sprintf(
                'Processed %d records in %d batches; %d batches failed.',
                $report->records,
                $report->batches,
                $report->failedBatches,
            ));
        }

        return $report->failedBatches > 0 ? self::FAILURE : self::SUCCESS;
    }
}
