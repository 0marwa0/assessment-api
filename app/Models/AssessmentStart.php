<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * R6: the questions drawn from pooled blocks for one attempt. Created by
 * POST /assessments/{id}/start, used once by the attempt it belongs to.
 */
#[Fillable(['user_id', 'assessment_id', 'question_ids', 'assessment_attempt_id'])]
class AssessmentStart extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_ids' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }
}
