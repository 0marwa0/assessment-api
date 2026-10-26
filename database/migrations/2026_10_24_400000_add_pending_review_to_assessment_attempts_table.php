<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * R4: how many questions of the attempt still wait for a score (a roleplay
     * call awaiting its AI / manager score, or a written answer awaiting the
     * manager). 0 = the result is final.
     */
    public function up(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('pending_review')->default(0)->after('xp_earned');
            $table->timestamp('finalized_at')->nullable()->after('pending_review');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_attempts', function (Blueprint $table) {
            $table->dropColumn(['pending_review', 'finalized_at']);
        });
    }
};
