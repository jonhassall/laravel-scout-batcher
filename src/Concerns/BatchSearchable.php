<?php

namespace LaravelScoutDebouncer\Concerns;

use Laravel\Scout\Searchable;
use LaravelScoutDebouncer\Contracts\PendingSearchRepository;

/**
 * Buffers Scout writes so they can be processed in batches later.
 */
trait BatchSearchable
{
    use Searchable {
        queueMakeSearchable as protected scoutQueueMakeSearchable;
        queueRemoveFromSearch as protected scoutQueueRemoveFromSearch;
        indexableAs as protected scoutIndexableAs;
    }

    /**
     * Stores an index name override for queued updates.
     */
    protected ?string $scoutDebouncerIndexOverride = null;

    /**
     * Preserve a queued delete's original index name when rebuilding a model.
     *
     * @internal
     */
    public function setScoutDebouncerIndexOverride(?string $indexName): static
    {
        $this->scoutDebouncerIndexOverride = $indexName;

        return $this;
    }

    /**
     * Resolve the effective index name for the model.
     */
    public function indexableAs()
    {
        return $this->scoutDebouncerIndexOverride ?? $this->scoutIndexableAs();
    }

    /**
     * Stage an upsert in the database instead of dispatching one Scout job.
     * Calling searchableSync() still writes to Scout immediately.
     */
    public function queueMakeSearchable($models)
    {
        if (! config('scout-batcher.enabled', true)) {
            return $this->scoutQueueMakeSearchable($models);
        }

        app(PendingSearchRepository::class)->enqueue($models, PendingSearchRepository::UPSERT);
    }

    /**
     * Stage a removal in the database instead of dispatching one Scout job.
     * Calling unsearchableSync() still writes to Scout immediately.
     */
    public function queueRemoveFromSearch($models)
    {
        if (! config('scout-batcher.enabled', true)) {
            return $this->scoutQueueRemoveFromSearch($models);
        }

        app(PendingSearchRepository::class)->enqueue($models, PendingSearchRepository::DELETE);
    }
}
