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
        if (! Schema::hasColumn('assessments', 'scoring_rules')) {
            Schema::table('assessments', function (Blueprint $table): void {
                $table->json('scoring_rules')->nullable()->after('status');
            });
        }

        DB::table('assessments')
            ->whereNull('scoring_rules')
            ->update([
                'scoring_rules' => json_encode($this->defaultScoringRules()),
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('assessments', 'scoring_rules')) {
            Schema::table('assessments', function (Blueprint $table): void {
                $table->dropColumn('scoring_rules');
            });
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultScoringRules(): array
    {
        return [
            'score_unit' => 'percent',
            'junior_max_score' => 59,
            'senior_min_score' => 85,
            'hard_gates_enabled' => true,
        ];
    }
};
