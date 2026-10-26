<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Q2: a block switched off in the builder is kept (with its questions) instead
// of being deleted on save. Existing blocks are all on.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blocks', function (Blueprint $table): void {
            $table->boolean('enabled')->default(true)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('blocks', function (Blueprint $table): void {
            $table->dropColumn('enabled');
        });
    }
};
