<?php

namespace LaravelScoutDebouncer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Represents a pending Scout update that has not yet been processed.
 */
class PendingSearchOperation extends Model
{
    /**
     * Allow all attributes to be mass-assigned for queued operations.
     */
    protected $guarded = [];

    /**
     * Cast queue-related fields to the correct PHP types.
     */
    protected $casts = [
        'queued_at' => 'immutable_datetime',
        'retry_at' => 'immutable_datetime',
        'claimed_at' => 'immutable_datetime',
        'attempts' => 'integer',
    ];

    /**
     * Resolve the configured database connection for pending operations.
     */
    public function getConnectionName()
    {
        return config('scout-batcher.connection') ?: parent::getConnectionName();
    }

    /**
     * Resolve the configured table name for pending operations.
     */
    public function getTable()
    {
        return config('scout-batcher.table', 'scout_batcher_pending');
    }

    /**
     * Define the polymorphic relationship to the searchable model.
     */
    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }
}
