<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentAssignmentResource extends JsonResource
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
            'user_id' => $this->user_id,
            'assessment_id' => $this->assessment_id,
            'assigned_by' => $this->assigned_by,
            'assigned_at' => $this->assigned_at?->toISOString(),
            'due_at' => $this->due_at?->toISOString(),
            'max_attempts' => $this->max_attempts,
            // Status for Home and the Coaching Hub (R8), from this learner's attempts.
            ...$this->status(),
            'assessment' => new AssessmentResource($this->whenLoaded('assessment')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function status(): array
    {
        $attempts = $this->resource->learnerAttempts();
        $last = $attempts->first();

        return [
            'attempts_count' => $attempts->count(),
            'passed' => $attempts->contains(fn ($attempt) => (bool) $attempt->passed),
            'best_score' => $attempts->max('score_pct'),
            'last_attempt' => $last ? [
                'id' => $last->id,
                'score_pct' => $last->score_pct,
                'passed' => (bool) $last->passed,
                'tier' => $last->tier,
                'created_at' => $last->created_at?->toISOString(),
            ] : null,
        ];
    }
}
