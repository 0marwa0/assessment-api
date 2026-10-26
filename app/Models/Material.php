<?php

namespace App\Models;

use App\Services\MaterialHtmlSanitizer;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['title', 'summary', 'content', 'attachments', 'category', 'tags', 'status', 'difficulty_level', 'estimated_time', 'xp', 'stages', 'created_by'])]
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'stages' => 'array',
            'attachments' => 'array',
            'xp' => 'integer',
        ];
    }

    /**
     * MG10: the one-page body is always stored sanitized, whatever writes it.
     */
    protected function content(): Attribute
    {
        return Attribute::make(
            set: function (?string $value): ?string {
                $clean = (new MaterialHtmlSanitizer)->sanitize($value);

                return $clean !== '' ? $clean : null;
            },
        );
    }

    /**
     * Plain text of the page (or of the old stages when there is no page yet).
     */
    public function plainText(): string
    {
        if (filled($this->content)) {
            return trim(html_entity_decode(strip_tags(str_replace(['<', '>'], [' <', '> '], (string) $this->content)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return trim(collect($this->stages ?? [])->map(fn ($stage) => is_array($stage) ? ($stage['title'] ?? '').' '.($stage['body'] ?? '') : '')->implode(' '));
    }

    /**
     * Reading time at 200 words a minute, at least 1 minute.
     */
    public function readingMinutes(): int
    {
        $words = count(preg_split('/\s+/u', $this->plainText(), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        return max(1, (int) ceil($words / 200));
    }
}
