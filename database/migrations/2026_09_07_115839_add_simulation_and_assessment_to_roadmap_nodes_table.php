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
        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->string('type', 20)->default('material')->after('roadmap_id');
            $table->foreignId('simulation_id')->nullable()->after('material_id')->constrained()->restrictOnDelete();
            $table->foreignId('assessment_id')->nullable()->after('simulation_id')->constrained()->restrictOnDelete();
        });

        // A node now references exactly one of material_id/simulation_id/assessment_id
        // (picked by `type`), so material_id can no longer be required.
        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->dropForeign(['simulation_id']);
            $table->dropForeign(['assessment_id']);
            $table->dropColumn(['type', 'simulation_id', 'assessment_id']);
        });

        Schema::table('roadmap_nodes', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable(false)->change();
        });
    }
};
