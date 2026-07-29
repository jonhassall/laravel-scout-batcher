<?php

namespace JonHassall\ScoutBatcher\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use JonHassall\ScoutBatcher\Contracts\PendingSearchRepository;
use JonHassall\ScoutBatcher\Tests\Fixtures\SearchablePost;
use JonHassall\ScoutBatcher\Tests\TestCase;

class DatabasePendingSearchRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });
    }

    public function test_trait_stages_scout_updates_in_the_database(): void
    {
        $post = SearchablePost::create(['title' => 'First']);

        $rows = app(PendingSearchRepository::class)->all();

        $this->assertCount(1, $rows);
        $this->assertSame(PendingSearchRepository::UPSERT, $rows->first()->operation);
        $this->assertSame((string) $post->getKey(), $rows->first()->searchable_id);
    }

    public function test_latest_operation_wins_without_resetting_first_queued_time(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $post = SearchablePost::create(['title' => 'First']);
        $firstQueuedAt = app(PendingSearchRepository::class)->all()->first()->queued_at;

        CarbonImmutable::setTestNow('2026-01-01 00:00:04');
        $post->delete();

        $rows = app(PendingSearchRepository::class)->all();

        $this->assertCount(1, $rows);
        $this->assertSame(PendingSearchRepository::DELETE, $rows->first()->operation);
        $this->assertEquals($firstQueuedAt, $rows->first()->queued_at);
    }

    public function test_full_batch_is_claimed_before_debounce_window(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        SearchablePost::insert([
            ['id' => 1, 'title' => 'One'],
            ['id' => 2, 'title' => 'Two'],
            ['id' => 3, 'title' => 'Three'],
        ]);

        app(PendingSearchRepository::class)->enqueue(
            SearchablePost::query()->get(),
            PendingSearchRepository::UPSERT,
        );

        $batch = app(PendingSearchRepository::class)->claimNextBatch();

        $this->assertNotNull($batch);
        $this->assertSame(3, $batch->count());
    }

    public function test_underfilled_batch_waits_until_debounce_window(): void
    {
        CarbonImmutable::setTestNow('2026-01-01 00:00:00');
        $post = SearchablePost::create(['title' => 'First']);
        $repository = app(PendingSearchRepository::class);

        $this->assertNull($repository->claimNextBatch());

        CarbonImmutable::setTestNow('2026-01-01 00:00:05');
        $batch = $repository->claimNextBatch();

        $this->assertNotNull($batch);
        $this->assertSame((string) $post->getKey(), $batch->records->first()->searchable_id);
    }
}
