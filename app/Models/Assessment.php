<?php

namespace App\Models;

use Database\Factories\AssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'description', 'type', 'status', 'scoring_rules', 'usage_analytics'])]
class Assessment extends Model
{
    /** @use HasFactory<AssessmentFactory> */
    use HasFactory;

    /**
     * @return HasMany<Block, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class)->orderBy('position');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scoring_rules' => 'array',
            'usage_analytics' => 'array',
        ];
    }
}
