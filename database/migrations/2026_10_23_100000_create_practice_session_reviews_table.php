<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MG2 / R7: manager Review Queue. Additive only — existing practice_sessions
// rows are untouched. A call is "to review" until it has a review row.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_session_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('practice_session_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('final_score');
            // [{name, ai_score, final_score, status: accepted|adjusted, reason}]
            $table->json('criteria')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
        });

        Schema::create('practice_session_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('practice_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('turn_index');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('practice_session_rereviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('practice_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('criterion')->nullable();
            $table->text('message');
            $table->unsignedInteger('turn_index')->nullable();
            $table->string('status')->default('open');
            $table->string('decision')->nullable();
            $table->unsignedTinyInteger('new_score')->nullable();
            $table->text('reply')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_session_rereviews');
        Schema::dropIfExists('practice_session_comments');
        Schema::dropIfExists('practice_session_reviews');
    }
};
