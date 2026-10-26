<?php

use App\Services\MaterialContentConverter;
use App\Services\MaterialHtmlSanitizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// MG10: converts every existing material's stages into the new one-page
// content + attachments (see MaterialContentConverter). Only rows that have
// no content yet are touched, and `stages` is left as it was, so this is
// safe to re-run and to roll back.
return new class extends Migration
{
    public function up(): void
    {
        $converter = new MaterialContentConverter;
        $sanitizer = new MaterialHtmlSanitizer;

        DB::table('materials')->whereNull('content')->orderBy('id')->each(function (object $row) use ($converter, $sanitizer): void {
            $stages = json_decode((string) $row->stages, true);
            $converted = $converter->fromStages(is_array($stages) ? $stages : []);
            $content = $sanitizer->sanitize($converted['content']);

            DB::table('materials')->where('id', $row->id)->update([
                'content' => $content !== '' ? $content : null,
                'attachments' => json_encode($converted['attachments']),
            ]);
        });
    }

    public function down(): void
    {
        // Nothing to undo: stages were never changed. The columns themselves
        // are dropped by the previous migration's down().
    }
};
