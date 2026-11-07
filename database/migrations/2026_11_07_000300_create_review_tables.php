<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->uuid('location_id');
            $table->string('source')->default('google');
            $table->string('external_id');
            $table->string('external_name');
            $table->string('reviewer_display_name')->nullable();
            $table->boolean('reviewer_is_anonymous')->default(false);
            $table->unsignedTinyInteger('star_rating');
            $table->text('comment')->nullable();
            $table->string('comment_language', 16)->nullable();
            $table->timestamp('created_at_source');
            $table->timestamp('updated_at_source');
            $table->text('owner_reply_text')->nullable();
            $table->timestamp('owner_reply_updated_at')->nullable();
            $table->string('owner_reply_origin')->default('none'); // reviewpilot, external, none
            $table->string('workflow_state')->default('received');
            $table->unsignedTinyInteger('priority')->default(0);
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->foreign(['organization_id', 'location_id'])
                ->references(['organization_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->unique(['location_id', 'source', 'external_id']);
            $table->index(['organization_id', 'workflow_state', 'priority']);
            $table->index(['location_id', 'created_at_source']);
            $table->index(['organization_id', 'star_rating']);
        });

        Schema::create('review_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('star_rating');
            $table->text('comment')->nullable();
            $table->timestamp('updated_at_source');
            $table->timestamp('captured_at')->useCurrent();
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('parent_key')->nullable();
            $table->string('vertical')->default('hospitality');
            $table->string('label_nl');
            $table->string('label_en');
            $table->unsignedSmallInteger('taxonomy_version')->default(1);
            $table->timestamps();
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->nullable()->index();
            $table->uuid('location_id')->nullable();
            $table->string('purpose'); // analysis, draft, voice_preview
            $table->string('provider');
            $table->string('model');
            $table->string('prompt_version');
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_microcents')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->string('status'); // ok, invalid_output, error
            $table->string('request_hash', 64)->nullable();
            $table->json('request_payload')->nullable();  // nulled after retention window
            $table->json('response_payload')->nullable(); // nulled after retention window
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
        });

        Schema::create('review_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_current')->default(true);
            $table->string('language', 16)->nullable();
            $table->decimal('language_confidence', 4, 3)->nullable();
            $table->decimal('sentiment_score', 4, 3)->default(0);
            $table->string('sentiment_label')->default('neutral');
            $table->unsignedTinyInteger('risk_score')->default(0);
            $table->json('risk_flags')->nullable();
            $table->boolean('requires_human')->default(false);
            $table->json('requires_human_reasons')->nullable();
            $table->decimal('confidence', 4, 3)->default(1);
            $table->text('summary')->nullable();
            $table->json('entities')->nullable();
            $table->json('keywords')->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->foreignUuid('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['review_id', 'is_current']);
        });

        Schema::create('review_topic_mentions', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id');
            $table->uuid('location_id');
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('analysis_id')->constrained('review_analyses')->cascadeOnDelete();
            $table->foreignUuid('topic_id')->constrained();
            $table->smallInteger('polarity'); // -1, 0, 1
            $table->text('evidence')->nullable();
            $table->timestamp('review_created_at');

            $table->index(['organization_id', 'topic_id', 'review_created_at']);
            $table->index(['location_id', 'topic_id', 'review_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_topic_mentions');
        Schema::dropIfExists('review_analyses');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('review_revisions');
        Schema::dropIfExists('reviews');
    }
};
