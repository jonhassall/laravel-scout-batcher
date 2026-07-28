<?php

namespace LaravelScoutDebouncer\Support;

/**
 * Stores a summary of the batches processed during a processor run.
 */
final class ProcessReport
{
    /**
     * Number of batches completed successfully.
     */
    public int $batches = 0;

    /**
     * Number of records processed successfully.
     */
    public int $records = 0;

    /**
     * Number of batches that failed processing.
     */
    public int $failedBatches = 0;

    /**
     * Record a successful batch in the report.
     */
    public function addSuccess(int $records): void
    {
        $this->batches++;
        $this->records += $records;
    }

    /**
     * Record a failed batch in the report.
     */
    public function addFailure(): void
    {
        $this->failedBatches++;
    }
}
