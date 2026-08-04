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
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
            $table->text('question_text');
            $table->string('type', 50);
            $table->json('options');
            $table->string('difficulty_level', 50);
            $table->unsignedInteger('reward')->default(0);
            $table->boolean('required_to_pass_block')->default(false);
            $table->text('answer_explanation')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['assessment_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
