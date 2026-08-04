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
        if (Schema::hasColumn('questions', 'question_type') && ! Schema::hasColumn('questions', 'type')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->renameColumn('question_type', 'type');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('questions', 'type') && ! Schema::hasColumn('questions', 'question_type')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->renameColumn('type', 'question_type');
            });
        }
    }
};
