<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['practice_session_id', 'reviewer_id', 'final_score', 'criteria', 'feedback', 'reviewed_at'])]
class PracticeSessionReview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'final_score' => 'integer',
            'criteria' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PracticeSession, $this>
     */
    public function practiceSession(): BelongsTo
    {
        return $this->belongsTo(PracticeSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }
}
