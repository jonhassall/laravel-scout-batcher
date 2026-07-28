<?php

namespace LaravelScoutDebouncer\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Scout\EngineManager;
use LaravelScoutDebouncer\Contracts\PendingSearchRepository;
use LaravelScoutDebouncer\ScoutBatchProcessor;
use LaravelScoutDebouncer\Tests\Fixtures\RecordingEngine;
use LaravelScoutDebouncer\Tests\Fixtures\SearchablePost;
use LaravelScoutDebouncer\Tests\TestCase;

class ScoutBatchProcessorTest extends TestCase
{
    private RecordingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        $this->engine = new RecordingEngine();
        $engine = $this->engine;
        app(EngineManager::class)->extend('recording', static fn () => $engine);
        config()->set('scout.driver', 'recording');
        app(EngineManager::class)->forgetEngines();
    }

    public function test_processor_sends_one_engine_update_for_a_full_batch(): void
    {
        SearchablePost::create(['title' => 'One']);
        SearchablePost::create(['title' => 'Two']);
        SearchablePost::create(['title' => 'Three']);

        $report = app(ScoutBatchProcessor::class)->process();

        $this->assertSame(1, $report->batches);
        $this->assertSame(3, $report->records);
        $this->assertSame([[1, 2, 3]], $this->engine->updates);
        $this->assertCount(0, app(PendingSearchRepository::class)->all());
    }

    public function test_processor_batches_deletions_without_reloading_deleted_models(): void
    {
        $posts = collect([
            SearchablePost::create(['title' => 'One']),
            SearchablePost::create(['title' => 'Two']),
            SearchablePost::create(['title' => 'Three']),
        ]);

        app(ScoutBatchProcessor::class)->process();
        $posts->each->delete();

        $report = app(ScoutBatchProcessor::class)->process();

        $this->assertSame(1, $report->batches);
        $this->assertSame([[1, 2, 3]], $this->engine->deletes);
    }

    public function test_missing_model_from_queued_upsert_is_deleted_from_scout(): void
    {
        $post = SearchablePost::create(['title' => 'Deleted without model events']);

        SearchablePost::query()->whereKey($post->getKey())->delete();

        $report = app(ScoutBatchProcessor::class)->process(force: true);

        $this->assertSame(1, $report->batches);
        $this->assertSame(1, $report->records);
        $this->assertSame([], $this->engine->updates);
        $this->assertSame([[(int) $post->getKey()]], $this->engine->deletes);
    }
}
