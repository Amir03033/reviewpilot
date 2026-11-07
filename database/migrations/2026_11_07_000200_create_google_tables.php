<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('google_sub');
            $table->string('google_email')->nullable();
            $table->text('access_token')->nullable();   // encrypted
            $table->timestamp('access_token_expires_at')->nullable();
            $table->text('refresh_token')->nullable();  // encrypted
            $table->json('scopes')->nullable();
            $table->string('status')->default('active'); // active, needs_reauth, revoked
            $table->timestamp('last_refreshed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'google_sub']);
        });

        Schema::create('gbp_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('google_connection_id')->constrained()->cascadeOnDelete();
            $table->string('account_name'); // accounts/123
            $table->string('display_name')->nullable();
            $table->string('type')->nullable();
            $table->string('role')->nullable();
            $table->timestamp('notifications_registered_at')->nullable();
            $table->timestamps();

            $table->unique(['google_connection_id', 'account_name']);
        });

        Schema::create('gbp_location_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('gbp_account_id')->constrained()->cascadeOnDelete();
            $table->uuid('location_id')->nullable()->unique();
            $table->string('gbp_location_name'); // locations/456
            $table->string('v4_parent');         // accounts/123/locations/456
            $table->string('title');
            $table->string('address')->nullable();
            $table->string('place_id')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->string('sync_state')->default('pending'); // pending, backfilling, active, error, paused
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_review_update_time')->nullable();
            $table->text('last_error')->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
            $table->unique(['gbp_account_id', 'gbp_location_name']);
            $table->index('gbp_location_name');
        });

        Schema::create('inbound_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source'); // google_pubsub, mollie
            $table->string('external_id');
            $table->json('payload')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            $table->unique(['source', 'external_id']);
        });

        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id')->index();
            $table->uuid('location_id')->nullable()->index();
            $table->string('kind'); // poll, backfill, reconcile, push
            $table->string('status')->default('running'); // running, ok, failed
            $table->unsignedInteger('api_calls')->default(0);
            $table->unsignedInteger('reviews_upserted')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
        Schema::dropIfExists('inbound_events');
        Schema::dropIfExists('gbp_location_links');
        Schema::dropIfExists('gbp_accounts');
        Schema::dropIfExists('google_connections');
    }
};
