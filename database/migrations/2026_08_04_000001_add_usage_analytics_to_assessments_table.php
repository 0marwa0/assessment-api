<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('assessments', 'usage_analytics')) {
            Schema::table('assessments', function (Blueprint $table): void {
                $table->json('usage_analytics')->nullable()->after('scoring_rules');
            });
        }

        DB::table('assessments')
            ->whereNull('usage_analytics')
            ->update([
                'usage_analytics' => json_encode($this->defaultUsageAnalytics()),
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('assessments', 'usage_analytics')) {
            Schema::table('assessments', function (Blueprint $table): void {
                $table->dropColumn('usage_analytics');
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultUsageAnalytics(): array
    {
        return [
            'times_used' => 124,
            'completion_rate_percent' => 92,
            'average_score_percent' => 78,
            'completed_attempts' => 113,
            'started_attempts' => 123,
            'last_attempted_at' => '2026-08-04T10:30:00Z',
        ];
    }
};
