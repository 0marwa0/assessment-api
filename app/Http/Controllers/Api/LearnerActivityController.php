<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentAttempt;
use App\Models\PracticeSession;
use App\Models\RoadmapAssignment;
use App\Models\SimulationAssignment;
use App\Models\RoadmapNodeProgress;
use App\Models\User;
use App\Models\XpAward;
use App\Services\AssessmentGrader;
use Illuminate\Http\JsonResponse;

class LearnerActivityController extends Controller
{
    /**
     * A manager-facing, merged timeline of everything a single broker has
     * done — roadmap step transitions, practice calls, and assessment
     * attempts — plus a per-roadmap "what stage are they on right now"
     * summary. Each source table already carries real timestamps and
     * scores; this just combines them into one feed sorted newest-first.
     */
    public function show(User $user): JsonResponse
    {
        $nodeProgress = RoadmapNodeProgress::query()
            ->where('user_id', $user->id)
            ->with(['roadmapNode.roadmap', 'roadmapNode.material', 'roadmapNode.simulation', 'roadmapNode.assessment'])
            ->get();

        $roadmapAssignments = RoadmapAssignment::query()
            ->where('user_id', $user->id)
            ->with(['roadmap.nodes', 'assignedBy:id,name'])
            ->get();

        $assessmentAssignments = AssessmentAssignment::query()
            ->where('user_id', $user->id)
            ->with(['assessment', 'assignedBy:id,name'])
            ->get()
            ->keyBy('assessment_id');

        $simulationAssignments = SimulationAssignment::query()
            ->where('user_id', $user->id)
            ->with(['simulation', 'assignedBy:id,name'])
            ->get();

        $practiceSessions = PracticeSession::query()
            ->where('user_id', $user->id)
            ->latest('started_at')
            ->get();

        $assessmentAttempts = AssessmentAttempt::query()
            ->where('user_id', $user->id)
            ->with('assessment')
            ->latest()
            ->get();

        $events = collect()
            ->concat($nodeProgress->map(function (RoadmapNodeProgress $progress): array {
                $node = $progress->roadmapNode;
                $content = $node?->content();

                return [
                    'type' => 'roadmap_node',
                    'label' => $content?->title ?? 'Untitled step',
                    'roadmap_title' => $node?->roadmap?->title,
                    'content_type' => $node?->type,
                    'status' => $progress->status,
                    'started_at' => $progress->started_at?->toISOString(),
                    'completed_at' => $progress->completed_at?->toISOString(),
                    'occurred_at' => ($progress->completed_at ?? $progress->started_at ?? $progress->updated_at)->toISOString(),
                ];
            }))
            ->concat($practiceSessions->map(fn (PracticeSession $session): array => [
                'type' => 'simulation',
                'session_id' => $session->id,
                'label' => $session->simulation_title,
                'score' => $session->score,
                'duration_seconds' => $session->duration_seconds,
                'started_at' => $session->started_at?->toISOString(),
                'ended_at' => $session->ended_at?->toISOString(),
                'occurred_at' => ($session->ended_at ?? $session->started_at ?? $session->created_at)->toISOString(),
            ]))
            ->concat($assessmentAttempts->map(fn (AssessmentAttempt $attempt): array => [
                'type' => 'assessment',
                'label' => $attempt->assessment?->title ?? 'Untitled assessment',
                'score_pct' => $attempt->score_pct,
                'passed' => $attempt->passed,
                'tier' => $attempt->tier,
                'pass_pct' => $attempt->assessment ? AssessmentGrader::rules($attempt->assessment)['pass_pct'] : null,
                // Attempt n (oldest = 1) of the assignment's limit, when there is one.
                'attempt_number' => $assessmentAttempts
                    ->where('assessment_id', $attempt->assessment_id)
                    ->filter(fn (AssessmentAttempt $other) => $other->created_at->lte($attempt->created_at) && $other->id <= $attempt->id)
                    ->count(),
                'max_attempts' => $assessmentAssignments->get($attempt->assessment_id)?->max_attempts,
                'total_correct' => $attempt->total_correct,
                'total_questions' => $attempt->total_questions,
                'elapsed_seconds' => $attempt->elapsed_seconds,
                'occurred_at' => $attempt->created_at->toISOString(),
            ]))
            ->concat($roadmapAssignments->map(fn (RoadmapAssignment $assignment): array => [
                'type' => 'assigned',
                'content_type' => 'roadmap',
                'label' => $assignment->roadmap?->title ?? 'Untitled roadmap',
                'assigned_by_name' => $assignment->assignedBy?->name,
                'due_at' => $assignment->due_at?->toISOString(),
                'occurred_at' => ($assignment->assigned_at ?? $assignment->created_at)->toISOString(),
            ]))
            ->concat($assessmentAssignments->values()->map(fn (AssessmentAssignment $assignment): array => [
                'type' => 'assigned',
                'content_type' => 'assessment',
                'label' => $assignment->assessment?->title ?? 'Untitled assessment',
                'assigned_by_name' => $assignment->assignedBy?->name,
                'due_at' => $assignment->due_at?->toISOString(),
                'occurred_at' => ($assignment->assigned_at ?? $assignment->created_at)->toISOString(),
            ]))
            ->concat($simulationAssignments->map(fn (SimulationAssignment $assignment): array => [
                'type' => 'assigned',
                'content_type' => 'simulation',
                'label' => $assignment->simulation?->title ?? 'Untitled simulation',
                'assigned_by_name' => $assignment->assignedBy?->name,
                'due_at' => $assignment->due_at?->toISOString(),
                'occurred_at' => ($assignment->assigned_at ?? $assignment->created_at)->toISOString(),
            ]))
            ->sortByDesc('occurred_at')
            ->values();

        $currentStages = $roadmapAssignments
            ->map(function (RoadmapAssignment $assignment) use ($nodeProgress): array {
                $nodes = $assignment->roadmap->nodes;
                $progressByNode = $nodeProgress->keyBy('roadmap_node_id');

                $currentNode = $nodes->first(function ($node) use ($progressByNode) {
                    $status = $progressByNode->get($node->id)?->status ?? 'not_started';

                    return $status !== 'done';
                });

                $currentProgress = $currentNode ? $progressByNode->get($currentNode->id) : null;

                $doneProgress = $nodes->map(fn ($node) => $progressByNode->get($node->id))->filter(fn ($p) => $p?->status === 'done');

                return [
                    'roadmap_id' => $assignment->roadmap_id,
                    'roadmap_title' => $assignment->roadmap->title,
                    'due_at' => $assignment->due_at?->toISOString(),
                    'completed_at' => $nodes->isNotEmpty() && $currentNode === null
                        ? $doneProgress->max('completed_at')?->toISOString()
                        : null,
                    'current_node' => $currentNode ? [
                        'id' => $currentNode->id,
                        'title' => $currentNode->content()?->title ?? 'Untitled step',
                        'content_type' => $currentNode->type,
                        'status' => $currentProgress?->status ?? 'not_started',
                        'started_at' => $currentProgress?->started_at?->toISOString(),
                    ] : null,
                    'nodes_done' => $nodes->filter(fn ($node) => ($progressByNode->get($node->id)?->status ?? null) === 'done')->count(),
                    'nodes_total' => $nodes->count(),
                ];
            })
            ->values();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'joined_at' => $user->created_at?->toISOString(),
                    'last_active_at' => $events->first()['occurred_at'] ?? null,
                ],
                'stats' => [
                    'xp_total' => (int) XpAward::query()->where('user_id', $user->id)->sum('amount'),
                    'calls' => $practiceSessions->count(),
                    'avg_call_score' => ($scored = $practiceSessions->whereNotNull('score'))->isNotEmpty()
                        ? (int) round($scored->avg('score'))
                        : null,
                    'streak_days' => app(GamificationController::class)->currentStreak($user->id),
                ],
                'current_stages' => $currentStages,
                'events' => $events,
            ],
        ]);
    }
}
