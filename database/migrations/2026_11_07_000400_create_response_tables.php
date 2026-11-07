<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_voices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('scope'); // organization, location
            $table->uuid('location_id')->nullable()->unique();
            $table->uuid('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign(['organization_id', 'location_id'])
                ->references(['organization_id', 'id'])->on('locations')->cascadeOnDelete();
        });

        Schema::create('brand_voice_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('brand_voice_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('settings');
            $table->json('preferred_phrases')->nullable();
            $table->json('forbidden_phrases')->nullable();
            $table->text('guidelines')->nullable();
            $table->text('special_instructions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['brand_voice_id', 'version']);
        });

        Schema::create('location_facts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('location_id');
            $table->string('key');
            $table->text('value');
            $table->string('source')->default('user'); // gbp, user
            $table->timestamps();

            $table->foreign(['organization_id', 'location_id'])
                ->references(['organization_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->unique(['location_id', 'key']);
        });

        Schema::create('automation_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('location_id')->unique();
            $table->unsignedTinyInteger('min_rating')->default(4);
            $table->unsignedTinyInteger('max_risk_score')->default(20);
            $table->json('allowed_languages')->nullable(); // null = any
            $table->unsignedInteger('hold_minutes')->default(120);
            $table->unsignedInteger('daily_cap')->default(20);
            $table->boolean('business_hours_only')->default(true);
            $table->timestamp('paused_until')->nullable();
            $table->string('paused_reason')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign(['organization_id', 'location_id'])
                ->references(['organization_id', 'id'])->on('locations')->cascadeOnDelete();
        });

        Schema::create('response_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('location_id');
            $table->foreignUuid('review_id')->constrained()->cascadeOnDelete();
            $table->string('variant'); // short, standard, warm, manual
            $table->text('text');
            $table->text('original_text')->nullable();
            $table->decimal('edit_distance_ratio', 5, 4)->nullable();
            $table->string('language', 16)->nullable();
            $table->foreignUuid('brand_voice_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('ai_generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('guardrail_results')->nullable();
            $table->boolean('guardrails_passed')->default(true);
            $table->string('status')->default('proposed'); // proposed, approved, rejected, superseded, stale
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['review_id', 'status']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('draft_id')->constrained('response_drafts')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('policy_id')->nullable();
            $table->string('decision'); // approve, edit_approve, reject, regenerate, auto_approve
            $table->text('note')->nullable();
            $table->timestamp('decided_at')->useCurrent();
        });

        Schema::create('review_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('draft_id')->nullable()->constrained('response_drafts')->nullOnDelete();
            $table->text('text');
            $table->string('state'); // scheduled, publishing, published, failed, deleted, cancelled
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('published_via')->default('user'); // user, automation
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('google_policy_rejection')->nullable();
            $table->timestamps();

            $table->index(['state', 'scheduled_for']);
        });

        Schema::create('publication_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->foreignUuid('reply_id')->constrained('review_replies')->cascadeOnDelete();
            $table->unsignedInteger('attempt_no');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('publication_attempts');
        Schema::dropIfExists('review_replies');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('response_drafts');
        Schema::dropIfExists('automation_policies');
        Schema::dropIfExists('location_facts');
        Schema::dropIfExists('brand_voice_versions');
        Schema::dropIfExists('brand_voices');
    }
};
