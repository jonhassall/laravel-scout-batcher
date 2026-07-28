<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('scout-batcher.connection'));

        $schema->create(config('scout-batcher.table'), function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('searchable_connection', 191)->default('');
            $table->string('searchable_type', 191);
            $table->string('searchable_id', 191);
            $table->text('scout_key');
            $table->string('index_name');
            $table->string('operation', 16);
            $table->timestamp('queued_at');
            $table->timestamp('retry_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(
                ['searchable_connection', 'searchable_type', 'searchable_id'],
                'scout_batcher_model_unique'
            );
            $table->index(['searchable_type', 'operation'], 'scout_batcher_group_lookup');
            $table->index(
                ['claimed_at', 'retry_at', 'queued_at'],
                'scout_batcher_availability_lookup'
            );
            $table->index('claim_token', 'scout_batcher_claim_token');
        });
    }

    public function down(): void
    {
        Schema::connection(config('scout-batcher.connection'))
            ->dropIfExists(config('scout-batcher.table'));
    }
};
