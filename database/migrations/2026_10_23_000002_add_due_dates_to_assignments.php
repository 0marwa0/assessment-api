<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// R8: optional due date on both kinds of assignment, and an optional retake
// limit on assessments. Nullable, so existing assignments are unchanged
// (no due date, unlimited attempts).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_assignments', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('assigned_at');
            $table->unsignedSmallInteger('max_attempts')->nullable()->after('due_at');
        });

        Schema::table('roadmap_assignments', function (Blueprint $table): void {
            $table->timestamp('due_at')->nullable()->after('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_assignments', function (Blueprint $table): void {
            $table->dropColumn(['due_at', 'max_attempts']);
        });

        Schema::table('roadmap_assignments', function (Blueprint $table): void {
            $table->dropColumn('due_at');
        });
    }
};
