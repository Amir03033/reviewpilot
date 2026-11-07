<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A Google location may be managed by at most ONE ReviewPilot location (across all
        // organisations). Two orgs importing the same GBP location would mean double replies.
        // Partial unique index: supported by both PostgreSQL and SQLite.
        DB::statement('CREATE UNIQUE INDEX gbp_location_links_active_unique ON gbp_location_links (gbp_location_name) WHERE location_id IS NOT NULL');

        Schema::table('subscriptions', function (Blueprint $table) {
            // When the subscription went past_due (grace period for failed payments).
            $table->timestamp('past_due_at')->nullable()->after('current_period_ends_at');
        });

        // Platform-wide switches (e.g. global automation pause). Kept in the DB, not the
        // cache, so a cache flush can never silently resume automatic publishing.
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('ai_generations', function (Blueprint $table) {
            $table->index('created_at'); // retention pruning
        });
    }

    public function down(): void
    {
        Schema::table('ai_generations', fn (Blueprint $table) => $table->dropIndex(['created_at']));
        Schema::dropIfExists('platform_settings');
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('past_due_at'));
        DB::statement('DROP INDEX IF EXISTS gbp_location_links_active_unique');
    }
};
