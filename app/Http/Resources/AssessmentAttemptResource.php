<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AssessmentAttemptResource extends JsonResource
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
            'assessment_assignment_id' => $this->assessment_assignment_id,
            'score_pct' => $this->score_pct,
            'passed' => $this->passed,
            'tier' => $this->tier,
            'total_correct' => $this->total_correct,
            'total_questions' => $this->total_questions,
            'xp_earned' => $this->xp_earned,
            // R4: questions still waiting for a score; 0 = the result is final.
            'pending_review' => (int) ($this->pending_review ?? 0),
            'finalized_at' => $this->finalized_at?->toISOString(),
            'elapsed_seconds' => $this->elapsed_seconds,
            'module_results' => $this->module_results,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
