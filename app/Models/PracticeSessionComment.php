<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['practice_session_id', 'author_id', 'turn_index', 'body'])]
class PracticeSessionComment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['turn_index' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
