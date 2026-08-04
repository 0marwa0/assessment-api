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
        Schema::table('assessments', function (Blueprint $table): void {
            if (! Schema::hasColumn('assessments', 'title')) {
                $table->string('title')->default('Untitled Assessment')->after('id');
            }

            if (! Schema::hasColumn('assessments', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
        });

        if (! Schema::hasTable('blocks')) {
            Schema::create('blocks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('assessment_id')->constrained()->cascadeOnDelete();
                $table->string('title');
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();

                $table->index(['assessment_id', 'position']);
            });
        }

        if (! Schema::hasColumn('questions', 'block_id')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->foreignId('block_id')->nullable()->after('assessment_id')->constrained('blocks')->cascadeOnDelete();
            });
        }

        DB::table('assessments')
            ->select('id')
            ->orderBy('id')
            ->each(function (object $assessment): void {
                $blockId = DB::table('blocks')->where('assessment_id', $assessment->id)->value('id');

                if (! $blockId) {
                    $blockId = DB::table('blocks')->insertGetId([
                        'assessment_id' => $assessment->id,
                        'title' => 'Default Block',
                        'position' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                DB::table('questions')
                    ->where('assessment_id', $assessment->id)
                    ->whereNull('block_id')
                    ->update([
                        'block_id' => $blockId,
                        'updated_at' => now(),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('questions', 'block_id')) {
            Schema::table('questions', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('block_id');
            });
        }

        Schema::dropIfExists('blocks');

        Schema::table('assessments', function (Blueprint $table): void {
            if (Schema::hasColumn('assessments', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('assessments', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
