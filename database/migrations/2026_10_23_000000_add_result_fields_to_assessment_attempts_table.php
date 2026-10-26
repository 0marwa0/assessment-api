<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Server-side grading (backlog R1) also records whether the attempt passed and
// which tier it reached. Nullable, so attempts saved before R1 are untouched.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table): void {
            $table->boolean('passed')->nullable()->after('score_pct');
            $table->string('tier')->nullable()->after('passed');
            $table->json('notes')->nullable()->after('module_results');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table): void {
            $table->dropColumn(['passed', 'tier', 'notes']);
        });
    }
};
