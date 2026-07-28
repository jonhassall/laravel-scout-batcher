<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Enable batching
    |--------------------------------------------------------------------------
    |
    | When disabled, BatchSearchable delegates to Scout's normal queueing
    | behavior.
    |
    */
    'enabled' => env('SCOUT_BATCHER_ENABLED', true),

    /*
    | Database connection and table used by the pending-operation outbox.
    | A null connection uses the application's default database connection.
    */
    'connection' => env('SCOUT_BATCHER_DB_CONNECTION'),
    'table' => env('SCOUT_BATCHER_TABLE', 'scout_batcher_pending'),

    /*
    | Process a model-type / operation group when either this many records are
    | waiting or the oldest record has waited this many seconds.
    */
    'debounce_seconds' => (int) env('SCOUT_BATCHER_SECONDS', 5),
    'max_batch_size' => (int) env('SCOUT_BATCHER_BATCH_SIZE', 1000),

    /* Keep database upserts below conservative parameter limits. */
    'enqueue_chunk_size' => (int) env('SCOUT_BATCHER_ENQUEUE_CHUNK', 100),

    /*
    | Limit work per scheduled invocation so one run cannot monopolize the
    | scheduler indefinitely. Set to 0 for no package-level limit.
    */
    'max_batches_per_run' => (int) env('SCOUT_BATCHER_MAX_BATCHES', 50),

    /*
    | Only one process command may run at a time. The lock uses this cache store,
    | or the application's default store when null. Multi-server deployments
    | must use a shared store that supports atomic locks.
    */
    'lock_store' => env('SCOUT_BATCHER_LOCK_STORE'),
    'lock_seconds' => (int) env('SCOUT_BATCHER_LOCK_SECONDS', 3600),

    /*
    | Claims older than this are considered abandoned and may be retried.
    */
    'claim_ttl_seconds' => (int) env('SCOUT_BATCHER_CLAIM_TTL', 300),

    /*
    | Failed batches remain in the table and become eligible again after this
    | delay. No queue or cache backend is required.
    */
    'retry_after_seconds' => (int) env('SCOUT_BATCHER_RETRY_AFTER', 30),

    'schedule' => [
        'enabled' => env('SCOUT_BATCHER_SCHEDULE_ENABLED', true),

        /* How often the scheduler checks for work. Whole-minute intervals and
         | Laravel's supported sub-minute frequencies are accepted as seconds.
         */
        'poll_seconds' => (int) env('SCOUT_BATCHER_POLL_SECONDS', 300),
    ],
];
