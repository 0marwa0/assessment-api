<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AssessmentAttempt;
use App\Models\PracticeSession;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNodeProgress;
use App\Models\SimulationAssignment;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Everything the manager's Coaching Hub needs in one request (design
 * "Manager · Coaching Hub"): every broker, when they were last active, and
 * for each assigned roadmap the progress, current step and due date.
 * Replaces the hub's one-request-per-broker-per-roadmap loading.
 * Also lists each broker's directly assigned practice calls (simulations):
 * calls made since assigning, best score and whether the target is met.
 */
class TeamOverviewController extends Controller
{
    public function index(): JsonResponse
    {
        $brokers = User::query()->role(UserRole::EndUser->value)->orderBy('name')->get(['id', 'name', 'email', 'created_at']);
        $ids = $brokers->pluck('id');

        $assignments = RoadmapAssignment::query()
            ->whereIn('user_id', $ids)
            ->with(['roadmap.nodes.material', 'roadmap.nodes.simulation', 'roadmap.nodes.assessment'])
            ->get()
            ->groupBy('user_id');

        $progress = RoadmapNodeProgress::query()
            ->whereIn('user_id', $ids)
            ->get(['user_id', 'roadmap_node_id', 'status', 'completed_at'])
            ->groupBy('user_id');

        $simulations = $this->simulationRows($ids->all());

        $lastActive = $this->lastActive($ids->all());
        $monthStart = now()->startOfMonth();
        $completedThisMonth = 0;
        $activeLast7Days = 0;

        $rows = $brokers->map(function (User $broker) use ($assignments, $progress, $simulations, $lastActive, $monthStart, &$completedThisMonth, &$activeLast7Days): array {
            $mine = ($progress[$broker->id] ?? collect())->keyBy('roadmap_node_id');
            $last = $lastActive[$broker->id] ?? null;
            if ($last !== null && $last->greaterThanOrEqualTo(now()->subDays(7))) {
                $activeLast7Days++;
            }

            $roadmaps = ($assignments[$broker->id] ?? collect())->map(function (RoadmapAssignment $assignment) use ($mine, $monthStart, &$completedThisMonth): array {
                $nodes = $assignment->roadmap?->nodes ?? collect();
                $doneNodes = $nodes->filter(fn ($node) => ($mine[$node->id]->status ?? null) === 'done');
                $currentIndex = $nodes->search(fn ($node) => ($mine[$node->id]->status ?? null) !== 'done');
                $current = $currentIndex === false ? null : $nodes[$currentIndex];

                $completedAt = null;
                if ($nodes->isNotEmpty() && $doneNodes->count() === $nodes->count()) {
                    $completedAt = $doneNodes->map(fn ($node) => $mine[$node->id]->completed_at)->filter()->max();
                    if ($completedAt !== null && Carbon::parse($completedAt)->greaterThanOrEqualTo($monthStart)) {
                        $completedThisMonth++;
                    }
                }

                return [
                    'roadmap_id' => $assignment->roadmap_id,
                    'title' => $assignment->roadmap?->title ?? 'Untitled roadmap',
                    'due_at' => $assignment->due_at?->toISOString(),
                    'total_steps' => $nodes->count(),
                    'done_steps' => $doneNodes->count(),
                    'current_step' => $current ? [
                        'position' => $currentIndex + 1,
                        'title' => $current->content()?->title ?? 'Untitled step',
                        'type' => $current->type,
                    ] : null,
                    'completed_at' => $completedAt ? Carbon::parse($completedAt)->toISOString() : null,
                ];
            })->values();

            return [
                'user_id' => $broker->id,
                'name' => $broker->name,
                'email' => $broker->email,
                'joined_at' => $broker->created_at?->toISOString(),
                'last_active_at' => $last?->toISOString(),
                'roadmaps' => $roadmaps,
                'simulations' => $simulations[$broker->id] ?? [],
            ];
        })->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'broker_count' => $brokers->count(),
                'active_last_7_days' => $activeLast7Days,
                'roadmaps_completed_this_month' => $completedThisMonth,
            ],
        ]);
    }

    /**
     * Assigned practice calls per broker, with the same "done" rule as
     * SimulationAssignment::isDone(): a finished call (with a transcript)
     * since assigning that meets the target score, if there is one.
     * Loads every relevant call in one query instead of one per assignment.
     *
     * @param  list<int>  $ids
     * @return array<int, list<array<string, mixed>>>
     */
    private function simulationRows(array $ids): array
    {
        $assignments = SimulationAssignment::query()
            ->whereIn('user_id', $ids)
            ->with('simulation:id,title,status')
            ->orderBy('assigned_at')
            ->get();
        if ($assignments->isEmpty()) {
            return [];
        }

        $sessions = PracticeSession::query()
            ->whereIn('user_id', $assignments->pluck('user_id')->unique()->all())
            ->whereIn('simulation_id', $assignments->pluck('simulation_id')->unique()->all())
            ->get(['id', 'user_id', 'simulation_id', 'score', 'transcript', 'created_at'])
            ->filter(fn (PracticeSession $session) => filled($session->transcript))
            ->groupBy(fn (PracticeSession $session) => "{$session->user_id}:{$session->simulation_id}");

        $rows = [];
        foreach ($assignments as $assignment) {
            $calls = ($sessions["{$assignment->user_id}:{$assignment->simulation_id}"] ?? collect())
                ->filter(fn (PracticeSession $session) => $session->created_at >= $assignment->assigned_at)
                ->sortByDesc('created_at')
                ->values();
            $scores = $calls->pluck('score')->filter(fn ($score) => $score !== null);
            $done = $calls->contains(fn (PracticeSession $session) => $assignment->min_score === null
                || ($session->score !== null && $session->score >= $assignment->min_score));

            $rows[$assignment->user_id][] = [
                'simulation_id' => $assignment->simulation_id,
                'title' => $assignment->simulation?->title ?? 'Untitled simulation',
                'published' => $assignment->simulation?->status === 'published',
                'assigned_at' => $assignment->assigned_at?->toISOString(),
                'due_at' => $assignment->due_at?->toISOString(),
                'min_score' => $assignment->min_score,
                'calls_count' => $calls->count(),
                'best_score' => $scores->isEmpty() ? null : (int) $scores->max(),
                'last_call_at' => $calls->first()?->created_at?->toISOString(),
                'last_session_id' => $calls->first()?->id,
                'done' => $done,
            ];
        }

        return $rows;
    }

    /**
     * Latest sign of activity per broker: a streak day, a practice call or an
     * assessment attempt.
     *
     * @param  list<int>  $ids
     * @return array<int, Carbon>
     */
    private function lastActive(array $ids): array
    {
        $sources = [
            UserActivityLog::query()->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, max(activity_date) as at')->get(),
            PracticeSession::query()->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, max(started_at) as at')->get(),
            AssessmentAttempt::query()->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id, max(created_at) as at')->get(),
        ];

        $latest = [];
        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                if ($row->at === null) {
                    continue;
                }
                $at = Carbon::parse($row->at);
                if (! isset($latest[$row->user_id]) || $at->greaterThan($latest[$row->user_id])) {
                    $latest[$row->user_id] = $at;
                }
            }
        }

        return $latest;
    }
}
