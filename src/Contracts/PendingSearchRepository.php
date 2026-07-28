<?php

namespace LaravelScoutDebouncer\Contracts;

use Illuminate\Support\Collection;
use LaravelScoutDebouncer\Support\ClaimedBatch;

/**
 * Defines how pending Scout operations are stored and claimed for processing.
 */
interface PendingSearchRepository
{
    public const UPSERT = 'upsert';
    public const DELETE = 'delete';

    /**
     * Queue one or more models for a Scout operation.
     */
    public function enqueue(iterable $models, string $operation): void;

    /**
     * Claim the next batch of pending work when it is ready.
     */
    public function claimNextBatch(bool $force = false): ?ClaimedBatch;

    /**
     * Mark a claimed batch as completed.
     */
    public function complete(ClaimedBatch $batch): int;

    /**
     * Release a failed batch so it can be retried later.
     */
    public function release(ClaimedBatch $batch, \Throwable $exception): int;

    /**
     * Return all pending records for inspection or testing.
     *
     * @return Collection<int, object>
     */
    public function all(): Collection;
}
