<?php

namespace LaravelScoutDebouncer\Support;

use Illuminate\Support\Collection;

/**
 * Represents a batch of pending Scout records that has been claimed for processing.
 */
final class ClaimedBatch
{
    /**
     * Create a claimed batch with the given metadata and rows.
     *
     * @param Collection<int, object> $records
     */
    public function __construct(
        public readonly string $token,
        public readonly string $searchableConnection,
        public readonly string $searchableType,
        public readonly string $indexName,
        public readonly string $operation,
        public readonly Collection $records,
    ) {
    }

    /**
     * Return the number of records in the batch.
     */
    public function count(): int
    {
        return $this->records->count();
    }
}
