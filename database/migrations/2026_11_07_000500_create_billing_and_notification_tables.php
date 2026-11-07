<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('plan');
            $table->string('status'); // pending, active, past_due, canceled
            $table->unsignedInteger('quantity');
            $table->unsignedInteger('amount_cents'); // monthly amount excl. VAT
            $table->string('currency', 3)->default('EUR');
            $table->string('mollie_subscription_id')->nullable()->unique();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mollie_payment_id')->unique();
            $table->string('sequence_type'); // first, recurring, oneoff
            $table->string('status');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('EUR');
            $table->string('description')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            $table->string('metric'); // reviews_processed, ai_generations, ai_cost_microcents
            $table->date('period');
            $table->unsignedBigInteger('quantity')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'metric', 'period']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('subscriptions');
    }
};
