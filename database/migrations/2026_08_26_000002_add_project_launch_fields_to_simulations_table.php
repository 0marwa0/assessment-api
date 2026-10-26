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
        Schema::table('simulations', function (Blueprint $table): void {
            $table->string('content_type', 30)->default('scenario')->after('status');
            $table->string('project_subtitle')->nullable()->after('content_type');
            $table->string('launch_tag', 50)->nullable()->after('project_subtitle');
            $table->unsignedInteger('xp')->nullable()->after('launch_tag');

            $table->index('content_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('simulations', function (Blueprint $table): void {
            $table->dropIndex(['content_type']);
            $table->dropColumn(['content_type', 'project_subtitle', 'launch_tag', 'xp']);
        });
    }
};
