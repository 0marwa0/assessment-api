<?php

namespace App\Services;

use App\Models\AssessmentAttempt;
use App\Models\PracticeSession;
use App\Models\Question;
use App\Models\User;
use App\Models\XpAward;
use Illuminate\Support\Facades\DB;

/**
 * R4: settles test attempts that were waiting for a score — a roleplay call
 * awaiting its AI / manager score, or a written answer awaiting the manager.
 *
 * finalize() re-scores the pending roleplay calls, rebuilds the totals
 * (AssessmentGrader::summarize), then syncs the side effects: the attempt's
 * single XpAward row is set to the new total (never a second award), and a
 * pass issues the certificate and completes the roadmap step. The broker is
 * told once, when the last pending question is decided. Safe to call again.
 */
class AttemptFinalizer
{
    public function __construct(
        private readonly CertificateIssuer $certificates,
        private readonly RoadmapProgress $roadmapProgress,
    ) {}

    /**
     * Finalizes every pending attempt that used this practice call.
     *
     * @return int how many attempts were updated
     */
    public function finalizeForSession(PracticeSession $session): int
    {
        if ($session->user_id === null) {
            return 0;
        }

        $updated = 0;
        AssessmentAttempt::query()
            ->where('user_id', $session->user_id)
            ->where('pending_review', '>', 0)
            ->get()
            ->filter(fn (AssessmentAttempt $attempt) => in_array((int) $session->id, self::sessionIds($attempt), true))
            ->each(function (AssessmentAttempt $attempt) use (&$updated) {
                $this->finalize($attempt);
                $updated++;
            });

        return $updated;
    }

    /**
     * Records a manager's grade (credit 0..1) for a written answer, keeping
     * earlier grades in its history, then finalizes the attempt.
     */
    public function gradeWrittenAnswer(AssessmentAttempt $attempt, string $questionId, float $credit, ?string $comment, User $manager): AssessmentAttempt
    {
        $modules = $attempt->module_results ?? [];
        $found = false;

        foreach ($modules as $m => $module) {
            foreach ($module['questionResults'] ?? [] as $i => $q) {
                if ((string) ($q['id'] ?? '') !== $questionId || ($q['type'] ?? null) !== QuestionTypes::SHORT_ANSWER) {
                    continue;
                }

                $history = $q['history'] ?? [];
                if (isset($q['decision'])) {
                    $history[] = $q['decision'];
                }
                $credit = max(0.0, min(1.0, $credit));
                $modules[$m]['questionResults'][$i] = [
                    ...$q,
                    'pending' => false,
                    'credit' => $credit,
                    'correct' => $credit >= 1.0,
                    // Graded answers always count, even a blank one.
                    'weight' => (float) (($q['weight'] ?? 1) ?: 1),
                    'decision' => [
                        'credit' => $credit,
                        'comment' => $comment,
                        'graded_by' => $manager->id,
                        'graded_by_name' => $manager->name,
                        'graded_at' => now()->toISOString(),
                    ],
                    'history' => $history,
                ];
                $found = true;
            }
        }

        abort_unless($found, 404, 'This question is not a written answer in this attempt.');

        $attempt->module_results = $modules;

        return $this->finalize($attempt);
    }

    public function finalize(AssessmentAttempt $attempt): AssessmentAttempt
    {
        return DB::transaction(function () use ($attempt) {
            $attempt = AssessmentAttempt::query()->lockForUpdate()->find($attempt->id)?->fill([
                'module_results' => $attempt->module_results,
            ]) ?? $attempt;
            $assessment = $attempt->assessment;
            $rules = AssessmentGrader::rules($assessment);
            $wasPending = (int) $attempt->pending_review > 0;
            $wasPassed = (bool) $attempt->passed;

            $modules = $attempt->module_results ?? [];
            foreach ($modules as $m => $module) {
                foreach ($module['questionResults'] ?? [] as $i => $q) {
                    if (($q['type'] ?? null) !== QuestionTypes::ROLEPLAY_CALL || ! ($q['pending'] ?? false)) {
                        continue;
                    }
                    $question = Question::query()->find((int) $q['id']);
                    if ($question === null) {
                        continue;
                    }
                    $modules[$m]['questionResults'][$i] = [
                        ...$q,
                        ...AssessmentGrader::roleplayResult($question, $q['session_ids'] ?? []),
                    ];
                }
            }

            $summary = AssessmentGrader::summarize($modules, $rules);
            $attempt->fill([
                'module_results' => $summary['module_results'],
                'score_pct' => $summary['score_pct'],
                'passed' => $summary['passed'],
                'tier' => $summary['tier'],
                'total_correct' => $summary['total_correct'],
                'total_questions' => $summary['total_questions'],
                'xp_earned' => $summary['xp_earned'],
                'pending_review' => $summary['pending_review'],
            ]);
            $nowFinal = $summary['pending_review'] === 0;
            if ($nowFinal && $attempt->finalized_at === null) {
                $attempt->finalized_at = now();
            }
            $attempt->save();

            $this->syncXp($attempt);

            if ($attempt->passed) {
                $this->certificates->issueFor($attempt);
                if (! $wasPassed && $attempt->user) {
                    $this->roadmapProgress->completeContent($attempt->user, 'assessment', (int) $attempt->assessment_id);
                }
            }

            if ($wasPending && $nowFinal) {
                Notifier::attemptFinal($attempt);
            }

            return $attempt;
        });
    }

    /** One XpAward row per attempt, always equal to the attempt's XP. */
    private function syncXp(AssessmentAttempt $attempt): void
    {
        $award = XpAward::query()
            ->where('source_type', 'assessment_attempt')
            ->where('source_id', $attempt->id)
            ->first();

        if ($award !== null) {
            $award->update(['amount' => (int) $attempt->xp_earned]);
        } elseif ($attempt->xp_earned > 0) {
            XpAward::query()->create([
                'user_id' => $attempt->user_id,
                'amount' => (int) $attempt->xp_earned,
                'source_type' => 'assessment_attempt',
                'source_id' => $attempt->id,
                'awarded_at' => now(),
            ]);
        }
    }

    /**
     * Practice session ids used by the attempt's roleplay call questions.
     *
     * @return list<int>
     */
    public static function sessionIds(AssessmentAttempt $attempt): array
    {
        $ids = [];
        foreach ($attempt->module_results ?? [] as $module) {
            foreach ($module['questionResults'] ?? [] as $q) {
                if (($q['type'] ?? null) === QuestionTypes::ROLEPLAY_CALL) {
                    array_push($ids, ...array_map('intval', $q['session_ids'] ?? []));
                }
            }
        }

        return $ids;
    }
}
