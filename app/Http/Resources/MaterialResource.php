<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'summary' => $this->summary,
            // MG10: one-page body (already sanitized) + attached files.
            'content' => $this->content,
            'attachments' => $this->attachments ?? [],
            'reading_minutes' => $this->readingMinutesValue(),
            'category' => $this->category,
            'tags' => $this->tags,
            'status' => $this->status,
            'difficulty_level' => $this->difficulty_level,
            'estimated_time' => $this->estimated_time,
            'xp' => $this->xp,
            'stages' => $this->stages,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            // Only on the manager's Material Bank list (MaterialController@index).
            'used_in_count' => $this->when($this->resource->getAttribute('used_in_count') !== null, fn () => $this->resource->getAttribute('used_in_count')),
            'read_by_count' => $this->when($this->resource->getAttribute('read_by_count') !== null, fn () => $this->resource->getAttribute('read_by_count')),
        ];
    }

    /**
     * The manager's estimated time when it is a number of minutes, otherwise
     * computed from the text (200 words a minute, at least 1).
     */
    private function readingMinutesValue(): int
    {
        if (filled($this->estimated_time) && preg_match('/\d+/', (string) $this->estimated_time, $match)) {
            return max(1, (int) $match[0]);
        }

        return $this->resource->readingMinutes();
    }
}
