<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: every new column is nullable, so existing simulations
     * are untouched and simply have no structured persona data yet.
     */
    public function up(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->json('objections')->nullable()->after('scenario_brief');
            $table->text('hidden_motivation')->nullable()->after('objections');
            $table->text('win_criteria')->nullable()->after('hidden_motivation');
            $table->text('loss_criteria')->nullable()->after('win_criteria');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn(['objections', 'hidden_motivation', 'win_criteria', 'loss_criteria']);
        });
    }
};
