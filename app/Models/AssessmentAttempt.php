<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'assessment_id', 'assessment_assignment_id',
    'score_pct', 'passed', 'tier', 'total_correct', 'total_questions', 'xp_earned', 'pending_review', 'finalized_at', 'elapsed_seconds', 'module_results', 'notes',
])]
class AssessmentAttempt extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score_pct' => 'integer',
            'passed' => 'boolean',
            'total_correct' => 'integer',
            'total_questions' => 'integer',
            'xp_earned' => 'integer',
            'pending_review' => 'integer',
            'finalized_at' => 'datetime',
            'elapsed_seconds' => 'integer',
            'module_results' => 'array',
            'notes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * @return BelongsTo<AssessmentAssignment, $this>
     */
    public function assessmentAssignment(): BelongsTo
    {
        return $this->belongsTo(AssessmentAssignment::class);
    }
}
