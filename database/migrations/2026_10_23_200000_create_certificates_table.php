<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backlog R9: one certificate per learner per assessment, issued when an
// attempt passes. A later, better attempt upgrades the same certificate.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('assessment_attempts')->nullOnDelete();
            $table->string('tier')->nullable();
            $table->unsignedTinyInteger('score_pct');
            $table->string('code', 16)->unique();
            $table->timestamp('issued_at');
            $table->timestamps();

            $table->unique(['user_id', 'assessment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
