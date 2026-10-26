<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Additive only: existing simulations get 'elevenlabs' (the current
     * engine) via the column default, so nothing changes for them.
     */
    public function up(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->string('voice_engine', 20)->default('elevenlabs')->after('language');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table) {
            $table->dropColumn('voice_engine');
        });
    }
};
