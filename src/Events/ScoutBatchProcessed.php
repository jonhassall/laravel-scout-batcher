<?php

namespace JonHassall\ScoutBatcher\Events;

use JonHassall\ScoutBatcher\Support\ClaimedBatch;

/**
 * Fired when a batch of pending Scout updates has been processed successfully.
 */
final class ScoutBatchProcessed
{
    /**
     * Create the event for a successfully processed batch.
     */
    public function __construct(public readonly ClaimedBatch $batch)
    {
    }
}
