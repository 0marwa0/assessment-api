<?php

namespace App\Services;

use App\Models\PracticeSession;
use App\Models\RoadmapAssignment;
use App\Models\RoadmapNode;
use App\Models\RoadmapNodeProgress;
use App\Models\User;
use App\Models\UserActivityLog;
use App\Models\XpAward;

/**
 * One place that marks a roadmap step done for a learner: progress row,
 * "showed up today" for the streak, and the step's XP (once per step).
 *
 * Readings are completed by the learner; simulations and assessments are
 * completed by the server (R8) when a call is saved or an assessment passed.
 */
class RoadmapProgress
{
    public function setStatus(User $user, RoadmapNode $node, string $status): RoadmapNodeProgress
    {
        // firstOrNew so started_at is set once, the first time the step is touched.
        $progress = RoadmapNodeProgress::query()->firstOrNew(
            ['user_id' => $user->id, 'roadmap_node_id' => $node->id],
        );

        if ($progress->started_at === null) {
            $progress->started_at = now();
        }

        $progress->status = $status;
        $progress->completed_at = $status === 'done' ? ($progress->completed_at ?? now()) : null;
        $progress->save();

        $this->recordActivityToday($user);

        // XP only on completion, once per step per user (unique constraint + firstOrCreate).
        if ($status === 'done') {
            $node->loadMissing(['material', 'simulation', 'assessment']);
            $content = $node->content();

            XpAward::query()->firstOrCreate(
                ['user_id' => $user->id, 'source_type' => 'roadmap_node', 'source_id' => $node->id],
                ['amount' => $content->xp ?? 0, 'awarded_at' => now()],
            );
        }

        return $progress;
    }

    /**
     * Marks every step that points at this simulation / assessment as done, in
     * the roadmaps assigned to the user. Returns how many steps were completed.
     *
     * MG8: simulation steps can carry a "pass score to unlock" (min_score).
     * Those only complete when $score >= min_score; a null score means "not
     * scored yet", so gated steps wait until the AI report or manager review
     * sets one (see completeForSession). Ungated steps complete as before.
     */
    public function completeContent(User $user, string $type, int $referenceId, ?int $score = null): int
    {
        $column = match ($type) {
            'simulation' => 'simulation_id',
            'assessment' => 'assessment_id',
            default => null,
        };

        if ($column === null) {
            return 0;
        }

        $roadmapIds = RoadmapAssignment::query()->where('user_id', $user->id)->pluck('roadmap_id');

        $nodes = RoadmapNode::query()
            ->whereIn('roadmap_id', $roadmapIds)
            ->where('type', $type)
            ->where($column, $referenceId)
            ->when($type === 'simulation', fn ($query) => $query->where(
                fn ($query) => $score === null
                    ? $query->whereNull('min_score')
                    : $query->whereNull('min_score')->orWhere('min_score', '<=', $score)
            ))
            ->get();

        foreach ($nodes as $node) {
            $this->setStatus($user, $node, 'done');
        }

        return $nodes->count();
    }

    /**
     * Completes the roadmap steps for a finished practice call, for the call's
     * own broker, using the call's current score. Called when the call is saved
     * and again whenever its score is set (AI report, grading, manager review).
     * Guest calls (no user) and empty calls are ignored.
     */
    public function completeForSession(PracticeSession $session): int
    {
        if ($session->simulation_id === null || $session->user_id === null || blank($session->transcript)) {
            return 0;
        }

        $user = $session->user;
        if ($user === null) {
            return 0;
        }

        return $this->completeContent(
            $user,
            'simulation',
            (int) $session->simulation_id,
            $session->score === null ? null : (int) $session->score,
        );
    }

    private function recordActivityToday(User $user): void
    {
        // whereDate() because the `date` cast stores a "00:00:00" suffix on SQLite.
        $today = now()->toDateString();
        $exists = UserActivityLog::query()
            ->where('user_id', $user->id)
            ->whereDate('activity_date', $today)
            ->exists();

        if (! $exists) {
            UserActivityLog::query()->create(['user_id' => $user->id, 'activity_date' => $today]);
        }
    }
}
