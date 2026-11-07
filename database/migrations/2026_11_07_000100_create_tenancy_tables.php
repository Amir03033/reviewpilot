<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->char('country', 2)->default('NL');
            $table->string('vat_number')->nullable();
            $table->string('default_locale', 10)->default('nl');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->boolean('automation_kill_switch')->default(false);
            $table->unsignedInteger('data_retention_days')->nullable();
            $table->json('settings')->nullable();

            // Billing (Mollie)
            $table->string('plan')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('mollie_customer_id')->nullable()->index();
            $table->string('mollie_mandate_id')->nullable();
            $table->string('billing_email')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUuid('current_organization_id')->nullable()->after('remember_token')
                ->constrained('organizations')->nullOnDelete();
            $table->string('locale', 10)->default('nl')->after('current_organization_id');
            $table->string('timezone')->default('Europe/Amsterdam')->after('locale');
        });

        Schema::create('organization_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // owner, admin, analyst, billing, member
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('address_line')->nullable();
            $table->string('city')->nullable();
            $table->string('postal_code')->nullable();
            $table->char('country', 2)->default('NL');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->string('primary_category')->nullable();
            $table->json('group_tags')->nullable();
            $table->string('status')->default('active'); // active, paused, disconnected
            $table->string('automation_mode')->default('smart'); // manual, smart, autopilot
            $table->boolean('billing_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Enables composite (organization_id, location_id) foreign keys on child tables.
            $table->unique(['organization_id', 'id']);
        });

        Schema::create('location_memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $table->uuid('location_id');
            $table->string('role'); // manager, contributor, viewer
            $table->timestamps();

            $table->foreign(['organization_id', 'location_id'])
                ->references(['organization_id', 'id'])->on('locations')->cascadeOnDelete();
            $table->unique(['membership_id', 'location_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->json('location_ids')->nullable();
            $table->string('location_role')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'email']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('organization_id')->nullable()->index();
            $table->string('actor_type'); // user, system, automation, admin
            $table->string('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->uuid('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['organization_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('location_memberships');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('organization_memberships');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('current_organization_id');
            $table->dropColumn(['locale', 'timezone']);
        });
        Schema::dropIfExists('organizations');
    }
};
