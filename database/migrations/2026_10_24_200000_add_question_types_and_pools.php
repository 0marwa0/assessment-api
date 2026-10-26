<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R6: more question types and question pools. All additive: existing
// questions keep settings = null (plain MCQ) and blocks keep pool_size = null
// (every question shown).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            // Per-type extras, e.g. {"scoring": "partial"} for ordering/matching
            // or {"model_answer": "..."} for a short written answer.
            $table->json('settings')->nullable()->after('options');
        });

        Schema::table('blocks', function (Blueprint $table): void {
            // Show this many random questions from the block per attempt.
            $table->unsignedSmallInteger('pool_size')->nullable()->after('enabled');
        });

        // The questions drawn for one attempt at an assessment with pools.
        // The attempt is graded on exactly these.
        Schema::create('assessment_starts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->json('question_ids');
            $table->foreignId('assessment_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_starts');

        Schema::table('blocks', function (Blueprint $table): void {
            $table->dropColumn('pool_size');
        });

        Schema::table('questions', function (Blueprint $table): void {
            $table->dropColumn('settings');
        });
    }
};
