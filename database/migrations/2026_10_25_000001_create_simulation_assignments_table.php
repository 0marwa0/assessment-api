<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Direct simulation assignments: a manager assigns one practice call to
// brokers, like an assessment. Optional due date and target score.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulation_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('simulation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->timestamp('due_at')->nullable();
            // Score a call needs to count as done; null = any finished call.
            $table->unsignedTinyInteger('min_score')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'simulation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulation_assignments');
    }
};
