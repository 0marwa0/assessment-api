<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MG8: "Pass score to unlock next" on simulation steps. Null = any finished call completes the step.
     */
    public function up(): void
    {
        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->unsignedTinyInteger('min_score')->nullable()->after('assessment_id');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->dropColumn('min_score');
        });
    }
};
