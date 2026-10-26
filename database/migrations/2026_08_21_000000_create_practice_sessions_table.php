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
        Schema::create('practice_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('simulation_id')->nullable()->constrained('simulations')->nullOnDelete();
            $table->string('simulation_title');
            $table->string('simulation_level', 50);
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->unsignedInteger('duration_seconds');
            $table->json('transcript');
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('practice_sessions');
    }
};
