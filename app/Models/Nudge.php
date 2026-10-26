<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// R12: a manager's nudge to a broker about one roadmap or assessment.
#[Fillable(['manager_id', 'user_id', 'item_type', 'item_id', 'message', 'via_app', 'via_email'])]
class Nudge extends Model
{
    protected function casts(): array
    {
        return [
            'manager_id' => 'integer',
            'user_id' => 'integer',
            'item_id' => 'integer',
            'via_app' => 'boolean',
            'via_email' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }
}
