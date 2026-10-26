<?php

namespace App\Services;

use App\Models\AssessmentAssignment;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNodeProgress;
use App\Models\SimulationAssignment;

/**
 * R12: what a nudge or a reminder is about — a roadmap, assessment or
 * simulation assignment — as a title, an in-app link, its due date and whether the
 * broker has finished it.
 */
class AssignmentItems
{
    /**
     * @return array{type: string, title: string, link: string, due_at: ?\Carbon\CarbonInterface}
     */
    public static function describe(RoadmapAssignment|AssessmentAssignment|SimulationAssignment $assignment): array
    {
        if ($assignment instanceof RoadmapAssignment) {
            return [
                'type' => 'roadmap',
                'title' => $assignment->roadmap?->title ?? 'Roadmap',
                'link' => '/roadmap/'.$assignment->roadmap_id,
                'due_at' => $assignment->due_at,
            ];
        }

        if ($assignment instanceof SimulationAssignment) {
            return [
                'type' => 'simulation',
                'title' => $assignment->simulation?->title ?? 'Practice call',
                'link' => '/practice?sim='.$assignment->simulation_id,
                'due_at' => $assignment->due_at,
            ];
        }

        return [
            'type' => 'assessment',
            'title' => $assignment->assessment?->title ?? 'Assessment',
            'link' => '/home',
            'due_at' => $assignment->due_at,
        ];
    }

    public static function isDone(RoadmapAssignment|AssessmentAssignment|SimulationAssignment $assignment): bool
    {
        if ($assignment instanceof AssessmentAssignment) {
            return $assignment->learnerAttempts()->contains('passed', true);
        }
        if ($assignment instanceof SimulationAssignment) {
            return $assignment->isDone();
        }

        $nodeIds = $assignment->roadmap?->nodes()->pluck('id') ?? collect();
        if ($nodeIds->isEmpty()) {
            return false;
        }

        $done = RoadmapNodeProgress::query()
            ->where('user_id', $assignment->user_id)
            ->whereIn('roadmap_node_id', $nodeIds)
            ->where('status', 'done')
            ->count();

        return $done >= $nodeIds->count();
    }

    /** Short status for the nudge line: "is overdue", "is due Oct 26", "is not started". */
    public static function statusPhrase(RoadmapAssignment|AssessmentAssignment|SimulationAssignment $assignment): string
    {
        $due = $assignment->due_at;
        if ($due !== null && $due->isPast()) {
            return 'is overdue';
        }
        if ($due !== null) {
            return 'is due '.$due->format('M j');
        }

        return 'is waiting for you';
    }
}
