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
        Schema::table('practice_sessions', function (Blueprint $table) {
            $table->foreignId('simulation_invite_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('guest_email')->nullable()->after('simulation_invite_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('practice_sessions', function (Blueprint $table) {
            $table->dropForeign(['simulation_invite_id']);
            $table->dropColumn(['simulation_invite_id', 'guest_email']);
        });
    }
};
