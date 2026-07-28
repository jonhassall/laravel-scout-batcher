<?php

namespace LaravelScoutDebouncer;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Scout\Jobs\RemoveableScoutCollection;
use LaravelScoutDebouncer\Contracts\PendingSearchRepository;
use LaravelScoutDebouncer\Events\ScoutBatchFailed;
use LaravelScoutDebouncer\Events\ScoutBatchProcessed;
use LaravelScoutDebouncer\Support\ClaimedBatch;
use LaravelScoutDebouncer\Support\ProcessReport;
use RuntimeException;
use Throwable;

/**
 * Processes queued Scout changes in batches using the configured repository.
 */
class ScoutBatchProcessor
{
    /**
     * Create a processor for the given repository.
     */
    public function __construct(private readonly PendingSearchRepository $repository)
    {
    }

    /**
     * Process pending batches up to the configured limit.
     */
    public function process(?int $maxBatches = null, bool $force = false): ProcessReport
    {
        $configuredLimit = (int) config('scout-batcher.max_batches_per_run', 50);
        $limit = $maxBatches ?? $configuredLimit;
        $report = new ProcessReport();

        while ($limit === 0 || ($report->batches + $report->failedBatches) < $limit) {
            // Try to claim the next eligible batch from the repository.
            $batch = $this->repository->claimNextBatch($force);

            if ($batch === null) {
                break;
            }

            try {
                $this->processBatch($batch);
                $this->repository->complete($batch);
            } catch (Throwable $exception) {
                $this->repository->release($batch, $exception);
                $report->addFailure();
                report($exception);
                $this->dispatchEvent(new ScoutBatchFailed($batch, $exception));

                continue;
            }

            $report->addSuccess($batch->count());
            $this->dispatchEvent(new ScoutBatchProcessed($batch));
        }

        return $report;
    }

    /**
     * Process a single claimed batch of pending operations.
     */
    public function processBatch(ClaimedBatch $batch): void
    {
        $class = Relation::getMorphedModel($batch->searchableType) ?: $batch->searchableType;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new RuntimeException("Unable to resolve searchable model [{$batch->searchableType}].");
        }

        /** @var Model $prototype */
        $prototype = new $class();
        $prototype->setConnection($batch->searchableConnection);

        if (! method_exists($prototype, 'searchableUsing')) {
            throw new RuntimeException("Model [{$class}] does not use Laravel Scout's Searchable trait.");
        }

        if ($batch->operation === PendingSearchRepository::DELETE) {
            $this->deleteByRecords($prototype, $batch);

            return;
        }

        if ($batch->operation !== PendingSearchRepository::UPSERT) {
            throw new RuntimeException("Unknown Scout debounce operation [{$batch->operation}].");
        }

        $this->upsertByRecords($prototype, $batch);
    }

    /**
     * Upsert the models represented by a batch into Scout.
     */
    private function upsertByRecords(Model $prototype, ClaimedBatch $batch): void
    {
        $ids = $batch->records->pluck('searchable_id')->all();

        /** @var EloquentCollection<int, Model> $models */
        $models = $prototype->newQueryForRestoration($ids)->get();
        $modelsByKey = $models->keyBy(fn (Model $model) => (string) $model->getKey());

        // Only sync models that are still meant to be searchable.
        $searchable = $models
            ->filter(fn (Model $model) => $model->shouldBeSearchable())
            ->values();

        $searchable
            ->groupBy(fn (Model $model) => (string) $model->indexableAs())
            ->each(function (EloquentCollection $models): void {
                if ($models->isNotEmpty()) {
                    $models->first()->syncMakeSearchable($models);
                }
            });

        // If a record no longer exists or should not be searchable, remove it from Scout.
        $recordsToDelete = $batch->records->filter(function (object $record) use ($modelsByKey): bool {
            $model = $modelsByKey->get((string) $record->searchable_id);

            return $model === null || ! $model->shouldBeSearchable();
        });

        if ($recordsToDelete->isNotEmpty()) {
            $this->deleteRecords($prototype, $recordsToDelete);
        }
    }

    /**
     * Delete the models represented by a batch from Scout.
     */
    private function deleteByRecords(Model $prototype, ClaimedBatch $batch): void
    {
        $this->deleteRecords($prototype, $batch->records);
    }

    /**
     * Delete the records contained in a collection from Scout.
     */
    private function deleteRecords(Model $prototype, $records): void
    {
        $models = RemoveableScoutCollection::make(
            $records->map(function (object $record) use ($prototype): Model {
                /** @var Model $model */
                $model = new ($prototype::class)();
                $model->setConnection($prototype->getConnectionName());
                $model->setKeyType($prototype->getScoutKeyType());

                // Build a minimal tombstone for records that may no longer exist
                // in the database. Scout deletions only need their stored key and
                // the index that was resolved when the operation was queued.
                $model->forceFill([
                    $model->getScoutKeyName() => $this->castScoutKey(
                        (string) $record->scout_key,
                        $prototype->getScoutKeyType(),
                    ),
                ]);

                if (method_exists($model, 'setScoutDebouncerIndexOverride')) {
                    $model->setScoutDebouncerIndexOverride((string) $record->index_name);
                }

                return $model;
            })->values()
        );

        $models
            ->groupBy(fn (Model $model) => (string) $model->indexableAs())
            ->each(function (EloquentCollection $group): void {
                $removable = RemoveableScoutCollection::make($group);

                if ($removable->isNotEmpty()) {
                    $removable->first()->searchableUsing()->delete($removable);
                }
            });
    }

    /**
     * Dispatch a batch event while absorbing event failures.
     */
    private function dispatchEvent(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            // Observability hooks must not requeue an already completed batch.
            report($exception);
        }
    }

    /**
     * Cast a Scout key to the expected scalar type.
     */
    private function castScoutKey(string $value, string $type): int|string
    {
        return in_array($type, ['int', 'integer'], true) ? (int) $value : $value;
    }
}
