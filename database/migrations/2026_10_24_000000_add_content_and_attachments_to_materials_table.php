<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MG10: a material becomes one rich-text page (sanitized HTML) plus a list
// of attached files. The old `stages` column stays for rollback.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->longText('content')->nullable()->after('summary');
            $table->json('attachments')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table): void {
            $table->dropColumn(['content', 'attachments']);
        });
    }
};
