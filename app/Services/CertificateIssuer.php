<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Certificate;
use Illuminate\Support\Str;

/**
 * Issues certificates (backlog R9). A learner holds at most one certificate
 * per assessment; it is created by their first passing attempt and upgraded
 * when a later attempt scores higher. Assessments with
 * scoring_rules.certificate_enabled = false never issue one.
 */
class CertificateIssuer
{
    /**
     * @param  bool  $notify  R12: tell the learner (bell + email) about a new
     *                        certificate or a higher tier; off for backfills.
     */
    public function issueFor(AssessmentAttempt $attempt, bool $notify = true): ?Certificate
    {
        if (! $attempt->passed) {
            return null;
        }

        $assessment = $attempt->assessment ?? Assessment::query()->find($attempt->assessment_id);
        if (! $assessment || ! AssessmentGrader::rules($assessment)['certificate_enabled']) {
            return null;
        }

        $certificate = Certificate::query()
            ->where('user_id', $attempt->user_id)
            ->where('assessment_id', $attempt->assessment_id)
            ->first();

        if ($certificate === null) {
            $certificate = Certificate::query()->create([
                'user_id' => $attempt->user_id,
                'assessment_id' => $attempt->assessment_id,
                'attempt_id' => $attempt->id,
                'tier' => $attempt->tier,
                'score_pct' => (int) $attempt->score_pct,
                'code' => $this->newCode(),
                'issued_at' => $attempt->created_at ?? now(),
            ]);

            if ($notify) {
                Notifier::certificateEarned($certificate);
            }

            return $certificate;
        }

        if ((int) $attempt->score_pct > $certificate->score_pct) {
            $tierChanged = $attempt->tier !== $certificate->tier;
            $certificate->update([
                'attempt_id' => $attempt->id,
                'tier' => $attempt->tier,
                'score_pct' => (int) $attempt->score_pct,
                'issued_at' => $attempt->created_at ?? now(),
            ]);

            if ($notify && $tierChanged) {
                Notifier::certificateEarned($certificate);
            }
        }

        return $certificate;
    }

    /**
     * Lazy backfill: attempts that passed before certificates existed get
     * their certificate the first time the learner's list is read.
     */
    public function backfillFor(int $userId): void
    {
        $have = Certificate::query()->where('user_id', $userId)->pluck('assessment_id')->all();

        AssessmentAttempt::query()
            ->with('assessment')
            ->where('user_id', $userId)
            ->where('passed', true)
            ->whereNotIn('assessment_id', $have)
            ->orderByDesc('score_pct')
            ->get()
            ->unique('assessment_id')
            ->each(fn (AssessmentAttempt $attempt) => $this->issueFor($attempt, notify: false));
    }

    private function newCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (Certificate::query()->where('code', $code)->exists());

        return $code;
    }
}
