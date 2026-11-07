<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Acceptance of the terms of service + data processing agreement (DPA).
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 20)->nullable();
            $table->foreignId('terms_accepted_by')->nullable()->constrained('users')->nullOnDelete();
            // Invoice address.
            $table->string('billing_name')->nullable();
            $table->string('billing_address')->nullable();
            $table->string('billing_postal_code', 20)->nullable();
            $table->string('billing_city')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false);
        });

        Schema::table('organization_memberships', function (Blueprint $table) {
            // Per-user, per-organisation email preferences. Null = defaults.
            $table->json('notification_preferences')->nullable();
        });

        // One counter row per year guarantees gap-free, sequential invoice numbers.
        Schema::create('invoice_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('number')->unique();
            $table->date('issued_on');
            $table->unsignedInteger('net_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('gross_cents');
            $table->decimal('vat_rate', 5, 4);
            $table->string('currency', 3)->default('EUR');
            // Snapshots: an invoice never changes after it is issued.
            $table->json('seller');
            $table->json('buyer');
            $table->json('lines');
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'issued_on']);
        });

        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending, ready, failed
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_counters');
        Schema::table('organization_memberships', fn (Blueprint $t) => $t->dropColumn('notification_preferences'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_platform_admin'));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('terms_accepted_by');
            $table->dropColumn(['terms_accepted_at', 'terms_version', 'billing_name', 'billing_address', 'billing_postal_code', 'billing_city']);
        });
    }
};
