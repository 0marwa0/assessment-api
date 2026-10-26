<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R3: a per-simulation weighted scorecard — [{name, weight}], weights sum
     * to 100. Null keeps the fixed 5-category AI rubric.
     */
    public function up(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->json('scorecard')->nullable()->after('loss_criteria');
        });
    }

    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn('scorecard');
        });
    }
};
