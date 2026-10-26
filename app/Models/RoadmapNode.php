<?php

namespace App\Models;

use Database\Factories\RoadmapNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['roadmap_id', 'type', 'material_id', 'simulation_id', 'assessment_id', 'min_score', 'position'])]
class RoadmapNode extends Model
{
    /** @use HasFactory<RoadmapNodeFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Roadmap, $this>
     */
    public function roadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class);
    }

    /**
     * @return BelongsTo<Material, $this>
     */
    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * @return BelongsTo<Simulation, $this>
     */
    public function simulation(): BelongsTo
    {
        return $this->belongsTo(Simulation::class);
    }

    /**
     * @return BelongsTo<Assessment, $this>
     */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(Assessment::class);
    }

    /**
     * The related content record for whichever type this node points to.
     */
    public function content(): Material|Simulation|Assessment|null
    {
        return match ($this->type) {
            'simulation' => $this->simulation,
            'assessment' => $this->assessment,
            default => $this->material,
        };
    }
}
