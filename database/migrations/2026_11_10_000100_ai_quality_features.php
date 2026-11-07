<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prompt caching + batch metering.
        Schema::table('ai_generations', function (Blueprint $table) {
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->boolean('batched')->default(false);
        });

        // Message Batches API for the first import of older reviews (50% cheaper).
        Schema::create('ai_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('anthropic_batch_id')->nullable()->unique();
            $table->string('purpose')->default('analysis');
            $table->string('status')->default('submitted'); // submitted, ended, failed
            $table->unsignedInteger('request_count')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_batch_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('ai_batch_id')->nullable()->constrained('ai_batches')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, submitted, done, failed
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Thumbs up / down on AI drafts.
        Schema::create('draft_feedback', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('location_id')->index();
            $table->foreignUuid('draft_id')->constrained('response_drafts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->smallInteger('rating'); // 1 = good, -1 = bad
            $table->string('reason')->nullable(); // tone, wrong_facts, too_long, too_generic, other
            $table->string('comment', 500)->nullable();
            $table->timestamps();

            $table->unique(['draft_id', 'user_id']);
        });

        // Weekly AI advice per location.
        Schema::create('location_insights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('location_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('headline');
            $table->text('summary');
            $table->json('recommendations');
            $table->json('praise')->nullable();
            $table->json('stats');
            $table->uuid('ai_generation_id')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'period_start']);
        });

        // Browser / phone push subscriptions.
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('public_key');
            $table->string('auth_token');
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('location_insights');
        Schema::dropIfExists('draft_feedback');
        Schema::dropIfExists('ai_batch_items');
        Schema::dropIfExists('ai_batches');
        Schema::table('ai_generations', fn (Blueprint $t) => $t->dropColumn(['cache_read_tokens', 'cache_write_tokens', 'batched']));
    }
};
