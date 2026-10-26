<?php

namespace App\Models;

use Database\Factories\AssessmentAssignmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Collection;

#[Fillable(['user_id', 'assessment_id', 'assigned_by', 'assigned_at', 'due_at', 'max_attempts'])]
class AssessmentAssignment extends Model
{
    /** @use HasFactory<AssessmentAssignmentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'assessment_id' => 'integer',
            'assigned_by' => 'integer',
            'assigned_at' => 'datetime',
            'due_at' => 'datetime',
            'max_attempts' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /** @var Collection<int, AssessmentAttempt>|null */
    private ?Collection $learnerAttemptsCache = null;

    /**
     * Every attempt this learner made at this assessment (from Home, a
     * roadmap step or elsewhere), newest first (R8 status).
     *
     * @return Collection<int, AssessmentAttempt>
     */
    public function learnerAttempts(): Collection
    {
        return $this->learnerAttemptsCache ??= AssessmentAttempt::query()
            ->where('user_id', $this->user_id)
            ->where('assessment_id', $this->assessment_id)
            ->latest()
            ->latest('id')
            ->get();
    }
}
