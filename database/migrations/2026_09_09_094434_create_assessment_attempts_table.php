<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('assessment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('score_pct');
            $table->unsignedInteger('total_correct');
            $table->unsignedInteger('total_questions');
            $table->unsignedInteger('xp_earned');
            $table->unsignedInteger('elapsed_seconds');
            $table->json('module_results');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_attempts');
    }
};
