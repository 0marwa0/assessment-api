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
        Schema::create('materials', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->text('summary');
            $table->string('category', 100);
            $table->json('tags');
            $table->string('status', 50)->default('draft');
            $table->string('difficulty_level', 50)->nullable();
            $table->string('estimated_time', 50)->nullable();
            $table->unsignedInteger('xp')->nullable();
            $table->json('stages');
            $table->string('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};
