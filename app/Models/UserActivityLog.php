<?php

namespace App\Models;

use Database\Factories\UserActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'activity_date'])]
class UserActivityLog extends Model
{
    /** @use HasFactory<UserActivityLogFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * Eloquent's default pluralization would look for "user_activity_logs" —
     * the migration created "user_activity_log" (singular), so this is
     * pinned explicitly rather than renaming the already-applied migration.
     */
    protected $table = 'user_activity_log';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'activity_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
