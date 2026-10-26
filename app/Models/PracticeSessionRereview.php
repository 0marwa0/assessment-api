<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['practice_session_id', 'user_id', 'criterion', 'message', 'turn_index', 'status', 'decision', 'new_score', 'reply', 'answered_by', 'answered_at'])]
class PracticeSessionRereview extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'turn_index' => 'integer',
            'new_score' => 'integer',
            'answered_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
