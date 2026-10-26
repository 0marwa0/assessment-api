<?php

namespace App\Models;

use Database\Factories\RoadmapNodeProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'roadmap_node_id', 'status', 'completed_at'])]
class RoadmapNodeProgress extends Model
{
    /** @use HasFactory<RoadmapNodeProgressFactory> */
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
            'roadmap_node_id' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<RoadmapNode, $this>
     */
    public function roadmapNode(): BelongsTo
    {
        return $this->belongsTo(RoadmapNode::class);
    }
}
