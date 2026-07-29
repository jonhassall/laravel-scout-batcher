<?php

namespace JonHassall\ScoutBatcher\Events;

use JonHassall\ScoutBatcher\Support\ClaimedBatch;

/**
 * Fired when a batch of pending Scout updates fails processing.
 */
final class ScoutBatchFailed
{
    /**
     * Create the event for a failed batch and the exception that caused it.
     */
    public function __construct(
        public readonly ClaimedBatch $batch,
        public readonly \Throwable $exception,
    ) {
    }
}
