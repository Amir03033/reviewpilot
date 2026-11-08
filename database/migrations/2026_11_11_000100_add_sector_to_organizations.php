<?php

use App\Support\TopicTaxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Existing customers are all hospitality businesses.
            $table->string('sector', 30)->default('hospitality');
        });

        // Topics for the new sectors (existing keys are kept and only relabelled).
        TopicTaxonomy::seed();
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $t) => $t->dropColumn('sector'));
    }
};
