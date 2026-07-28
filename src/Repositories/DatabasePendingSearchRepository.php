<?php

namespace LaravelScoutDebouncer\Repositories;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LaravelScoutDebouncer\Contracts\PendingSearchRepository;
use LaravelScoutDebouncer\Models\PendingSearchOperation;
use LaravelScoutDebouncer\Support\ClaimedBatch;

/**
 * Persists pending Scout operations in the database and claims them in batches.
 */
class DatabasePendingSearchRepository implements PendingSearchRepository
{
    /**
     * Create the repository with the application database manager.
     */
    public function __construct(private readonly DatabaseManager $database)
    {
    }

    /**
     * Queue models for a Scout upsert or delete operation.
     */
    public function enqueue(iterable $models, string $operation): void
    {
        if (! in_array($operation, [self::UPSERT, self::DELETE], true)) {
            throw new InvalidArgumentException("Unsupported Scout debounce operation [{$operation}].");
        }

        $now = CarbonImmutable::now();
        // Only persist models that can be identified and represented in Scout.
        $rows = collect($models)
            ->filter(fn ($model) => $model instanceof Model && $model->getKey() !== null)
            ->map(function (Model $model) use ($operation, $now): array {
                return [
                    'searchable_connection' => (string) $model->getConnection()->getName(),
                    'searchable_type' => $model->getMorphClass(),
                    'searchable_id' => (string) $model->getKey(),
                    'scout_key' => (string) $model->getScoutKey(),
                    'index_name' => (string) $model->indexableAs(),
                    'operation' => $operation,
                    'queued_at' => $now,
                    'retry_at' => null,
                    'claimed_at' => null,
                    'claim_token' => null,
                    'attempts' => 0,
                    'last_error' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, max(1, (int) config('scout-batcher.enqueue_chunk_size', 100))) as $chunk) {
            $this->connection()->table($this->table())->upsert(
                $chunk,
                ['searchable_connection', 'searchable_type', 'searchable_id'],
                [
                    'scout_key',
                    'index_name',
                    'operation',
                    'retry_at',
                    'claimed_at',
                    'claim_token',
                    'attempts',
                    'last_error',
                    'updated_at',
                ],
            );
        }
    }

    /**
     * Claim the next eligible batch of pending operations.
     */
    public function claimNextBatch(bool $force = false): ?ClaimedBatch
    {
        $batchSize = max(1, (int) config('scout-batcher.max_batch_size', 1000));
        $debounceSeconds = max(0, (int) config('scout-batcher.debounce_seconds', 5));
        $claimTtl = max(1, (int) config('scout-batcher.claim_ttl_seconds', 300));
        $now = CarbonImmutable::now();
        $staleBefore = $now->subSeconds($claimTtl);

        $groups = $this->availableQuery($now, $staleBefore)
            ->select(['searchable_connection', 'searchable_type', 'index_name', 'operation'])
            ->selectRaw('MIN(queued_at) as oldest_queued_at')
            ->selectRaw('COUNT(*) as pending_count')
            ->groupBy('searchable_connection', 'searchable_type', 'index_name', 'operation')
            ->orderBy('oldest_queued_at')
            ->get();

        foreach ($groups as $group) {
            // A batch becomes eligible once it is full or has been waiting long enough.
            $isFull = (int) $group->pending_count >= $batchSize;
            $isDue = CarbonImmutable::parse($group->oldest_queued_at)
                ->lessThanOrEqualTo($now->subSeconds($debounceSeconds));

            if (! $force && ! $isFull && ! $isDue) {
                continue;
            }

            $claimed = $this->claimGroup(
                (string) $group->searchable_connection,
                (string) $group->searchable_type,
                (string) $group->index_name,
                (string) $group->operation,
                $batchSize,
                $now,
                $staleBefore,
                $force,
                $debounceSeconds,
            );

            if ($claimed !== null) {
                return $claimed;
            }
        }

        return null;
    }

    /**
     * Mark a claimed batch as completed and remove its rows.
     */
    public function complete(ClaimedBatch $batch): int
    {
        return $this->connection()->table($this->table())
            ->where('claim_token', $batch->token)
            ->delete();
    }

    /**
     * Release a failed batch so it can be retried later.
     */
    public function release(ClaimedBatch $batch, \Throwable $exception): int
    {
        $retryAt = CarbonImmutable::now()->addSeconds(
            max(1, (int) config('scout-batcher.retry_after_seconds', 30))
        );

        return $this->connection()->table($this->table())
            ->where('claim_token', $batch->token)
            ->update([
                // Reset the claim state and schedule a retry after a failure.
                'claimed_at' => null,
                'claim_token' => null,
                'retry_at' => $retryAt,
                'attempts' => $this->connection()->raw('attempts + 1'),
                'last_error' => Str::limit($exception->getMessage(), 65535, ''),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    /**
     * Return all pending rows in order.
     */
    public function all(): Collection
    {
        return $this->connection()->table($this->table())->orderBy('id')->get();
    }

    /**
     * Claim one eligible group of rows inside a transaction.
     */
    private function claimGroup(
        string $searchableConnection,
        string $searchableType,
        string $indexName,
        string $operation,
        int $batchSize,
        CarbonImmutable $now,
        CarbonImmutable $staleBefore,
        bool $force,
        int $debounceSeconds,
    ): ?ClaimedBatch {
        return $this->connection()->transaction(function () use (
            $searchableConnection,
            $searchableType,
            $indexName,
            $operation,
            $batchSize,
            $now,
            $staleBefore,
            $force,
            $debounceSeconds,
        ): ?ClaimedBatch {
            $rows = $this->availableQuery($now, $staleBefore)
                ->where('searchable_connection', $searchableConnection)
                ->where('searchable_type', $searchableType)
                ->where('index_name', $indexName)
                ->where('operation', $operation)
                ->orderBy('queued_at')
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return null;
            }

            $isFull = $rows->count() >= $batchSize;
            $isDue = CarbonImmutable::parse($rows->first()->queued_at)
                ->lessThanOrEqualTo($now->subSeconds($debounceSeconds));

            // Re-check eligibility while holding locks. Another worker may have
            // claimed part of a group after the aggregate query was read.
            if (! $force && ! $isFull && ! $isDue) {
                return null;
            }

            $token = (string) Str::uuid();
            $ids = $rows->pluck('id')->all();

            // Mark these rows as claimed so other workers do not process the same group.
            $this->connection()->table($this->table())
                ->whereIn('id', $ids)
                ->update([
                    'claimed_at' => $now,
                    'claim_token' => $token,
                    'updated_at' => $now,
                ]);

            return new ClaimedBatch(
                $token,
                $searchableConnection,
                $searchableType,
                $indexName,
                $operation,
                $rows,
            );
        });
    }

    /**
     * Build the shared query for pending rows that are eligible to process.
     */
    private function availableQuery(CarbonImmutable $now, CarbonImmutable $staleBefore)
    {
        return $this->connection()->table($this->table())
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('claimed_at')->orWhere('claimed_at', '<=', $staleBefore);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('retry_at')->orWhere('retry_at', '<=', $now);
            });
    }

    /**
     * Resolve the database connection for pending operations.
     */
    private function connection(): ConnectionInterface
    {
        return $this->database->connection(config('scout-batcher.connection'));
    }

    /**
     * Resolve the table name used for pending operations.
     */
    private function table(): string
    {
        return (new PendingSearchOperation())->getTable();
    }
}
