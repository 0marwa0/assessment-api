<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssessmentAttemptRequest;
use App\Http\Resources\AssessmentAttemptResource;
use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentStart;
use App\Models\Certificate;
use App\Models\PracticeSession;
use App\Models\XpAward;
use App\Services\AssessmentGrader;
use App\Services\CertificateIssuer;
use App\Services\QuestionPool;
use App\Services\QuestionTypes;
use App\Services\RoadmapProgress;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssessmentAttemptController extends Controller
{
    /**
     * Grades a broker's answers on the server and records the attempt. The
     * browser never sees the correct answers before submitting, and can't
     * choose its own score.
     */
    public function store(
        StoreAssessmentAttemptRequest $request,
        AssessmentGrader $grader,
        RoadmapProgress $roadmapProgress,
        CertificateIssuer $certificates,
        QuestionPool $pool,
    ): AssessmentAttemptResource {
        $assessment = Assessment::query()->findOrFail($request->integer('assessment_id'));

        $assignmentId = $request->validated('assessment_assignment_id');
        if ($assignmentId !== null) {
            $assignment = AssessmentAssignment::query()->findOrFail($assignmentId);
            abort_unless(
                $assignment->user_id === $request->user()->id && (int) $assignment->assessment_id === $assessment->id,
                403,
                'This assignment is not yours.'
            );

            // Retake limit (R8): counts every attempt at this assessment by this learner.
            if ($assignment->max_attempts !== null) {
                $used = AssessmentAttempt::query()
                    ->where('user_id', $request->user()->id)
                    ->where('assessment_id', $assessment->id)
                    ->count();
                abort_if($used >= $assignment->max_attempts, 422, 'You have used all your attempts for this assessment.');
            }
        }

        // R6 pools: an assessment with pooled blocks is graded on the questions
        // drawn when the learner started it (POST /assessments/{id}/start).
        $start = null;
        $questionIds = null;
        if ($pool->hasPools($assessment)) {
            $start = AssessmentStart::query()->find($request->validated('assessment_start_id'));
            abort_if(
                $start === null || $start->user_id !== $request->user()->id || (int) $start->assessment_id !== $assessment->id,
                422,
                'Start the assessment again: its questions are drawn when you start.'
            );
            abort_if($start->assessment_attempt_id !== null, 422, 'These answers were already submitted. Start the assessment again.');
            $questionIds = array_map('intval', $start->question_ids ?? []);
            $known = $assessment->blocks->flatMap->questions->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_if(
                array_diff($questionIds, $known) !== [],
                422,
                'This assessment changed after you started it. Start it again to get the new questions.'
            );
        }

        $this->validateRoleplayAnswers($assessment, $request->validated('answers'), $questionIds, $request->user()->id);

        $result = $grader->grade($assessment, $request->validated('answers'), $questionIds);

        // Stored as a list of {question_id, note} so the question ids survive JSON.
        $notes = collect($request->validated('notes') ?? [])
            ->filter(fn ($note) => filled($note))
            ->map(fn ($note, $questionId) => ['question_id' => (string) $questionId, 'note' => trim((string) $note)])
            ->values();

        $attempt = AssessmentAttempt::create([
            'user_id' => $request->user()->id,
            'assessment_id' => $assessment->id,
            'assessment_assignment_id' => $assignmentId,
            'score_pct' => $result['score_pct'],
            'passed' => $result['passed'],
            'tier' => $result['tier'],
            'total_correct' => $result['total_correct'],
            'total_questions' => $result['total_questions'],
            'xp_earned' => $result['xp_earned'],
            // R4: questions still waiting for a score (call / written answer).
            'pending_review' => $result['pending_review'],
            'finalized_at' => $result['pending_review'] === 0 ? now() : null,
            'elapsed_seconds' => $request->integer('elapsed_seconds'),
            'module_results' => $result['module_results'],
            'notes' => $notes->isEmpty() ? null : $notes->all(),
        ]);

        $start?->update(['assessment_attempt_id' => $attempt->id]);

        // Passing completes the matching roadmap step (R8).
        if ($attempt->passed) {
            $roadmapProgress->completeContent($request->user(), 'assessment', $assessment->id);
        }

        if ($attempt->xp_earned > 0) {
            XpAward::query()->create([
                'user_id' => $attempt->user_id,
                'amount' => $attempt->xp_earned,
                'source_type' => 'assessment_attempt',
                'source_id' => $attempt->id,
                'awarded_at' => now(),
            ]);
        }

        // R9: a passing attempt earns (or upgrades) the certificate.
        $certificate = $certificates->issueFor($attempt);

        return $this->resultResource($attempt, $assessment, $result['answer_key'])
            ->additional([
                ...$this->resultExtras($attempt, $assessment, $certificate?->code),
                'pass_pct' => $result['pass_pct'],
                'message' => 'Assessment attempt graded successfully.',
            ]);
    }

    /**
     * A past attempt's result (e.g. from the "result is final" notification),
     * in the same shape as store(), honouring the assessment's
     * results_visibility. The owner or a manager only.
     */
    public function show(Request $request, AssessmentAttempt $attempt, AssessmentGrader $grader): AssessmentAttemptResource
    {
        abort_unless(
            $request->user()->isManager() || $attempt->user_id === $request->user()->id,
            403,
            'You can only see your own results.'
        );

        $assessment = $attempt->assessment()->firstOrFail();
        $questionIds = collect($attempt->module_results ?? [])
            ->flatMap(fn ($m) => array_column($m['questionResults'] ?? [], 'id'))
            ->all();
        $answerKey = $grader->answerKey($assessment, $questionIds);
        $certificateCode = Certificate::query()->where('attempt_id', $attempt->id)->value('code');

        return $this->resultResource($attempt, $assessment, $answerKey)
            ->additional([
                ...$this->resultExtras($attempt, $assessment, $certificateCode),
                'pass_pct' => AssessmentGrader::rules($assessment)['pass_pct'],
                'assessment_title' => $assessment->title,
            ]);
    }

    /** The attempt with module_results revealed per results_visibility (in memory only). */
    private function resultResource(AssessmentAttempt $attempt, Assessment $assessment, array $answerKey): AssessmentAttemptResource
    {
        $rules = AssessmentGrader::rules($assessment);
        $attempt->module_results = $this->revealedModuleResults(
            $attempt->module_results ?? [], $answerKey, $rules['results_visibility']
        );

        return new AssessmentAttemptResource($attempt);
    }

    /** @return array<string, mixed> */
    private function resultExtras(AssessmentAttempt $attempt, Assessment $assessment, ?string $certificateCode): array
    {
        $pending = (int) ($attempt->pending_review ?? 0);

        return [
            'results_visibility' => AssessmentGrader::rules($assessment)['results_visibility'],
            'attempt_number' => AssessmentAttempt::query()
                ->where('user_id', $attempt->user_id)
                ->where('assessment_id', $assessment->id)
                ->where('id', '<=', $attempt->id)
                ->count(),
            'certificate_code' => $certificateCode,
            // R6 / R4: questions waiting for a score (written answers for the
            // manager, roleplay calls for their score). Not in the score yet,
            // and the attempt can't pass until it is 0.
            'pending_review' => $pending,
            'is_final' => $pending === 0,
        ];
    }

    /**
     * R4: the answer to a roleplay call question is the learner's own practice
     * session id(s) for that question's simulation (up to `attempts`), each with
     * a transcript.
     *
     * @param  array<int|string, mixed>  $answers
     * @param  list<int>|null  $questionIds
     */
    private function validateRoleplayAnswers(Assessment $assessment, array $answers, ?array $questionIds, int $userId): void
    {
        $errors = [];
        $questions = $assessment->blocks->filter(fn ($b) => $b->enabled !== false)->flatMap->questions
            ->filter(fn ($q) => QuestionTypes::isRoleplay($q->type))
            ->filter(fn ($q) => $questionIds === null || in_array((int) $q->id, $questionIds, true));

        foreach ($questions as $question) {
            $answer = $answers[$question->id] ?? $answers[(string) $question->id] ?? null;
            if ($answer === null || $answer === []) {
                continue;
            }

            $field = "answers.{$question->id}";
            $ids = is_array($answer) ? $answer : [$answer];
            if (collect($ids)->contains(fn ($id) => ! is_int($id) && ! (is_string($id) && ctype_digit($id)))) {
                $errors[$field] = 'Send the practice call id for this question.';

                continue;
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            $settings = QuestionTypes::roleplaySettings($question->settings);

            if (count($ids) > $settings['attempts']) {
                $errors[$field] = "This call allows {$settings['attempts']} attempt(s).";

                continue;
            }

            $sessions = PracticeSession::query()->whereIn('id', $ids)->get();
            $valid = $sessions->count() === count($ids) && $sessions->every(
                fn (PracticeSession $s) => (int) $s->user_id === $userId
                    && (int) $s->simulation_id === (int) $settings['simulation_id']
                    && filled($s->transcript)
            );
            if (! $valid) {
                $errors[$field] = 'That practice call is not yours, is for another simulation, or has no transcript.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * full: answers + correct answers + explanations; answers: no explanations;
     * score: module totals only, no per-question detail.
     *
     * @param  list<array<string, mixed>>  $moduleResults
     * @param  array<string, array<string, mixed>>  $answerKey
     * @return list<array<string, mixed>>
     */
    private function revealedModuleResults(array $moduleResults, array $answerKey, string $visibility): array
    {
        return array_map(function (array $module) use ($answerKey, $visibility) {
            if ($visibility === 'score') {
                $module['questionResults'] = [];

                return $module;
            }

            $module['questionResults'] = array_map(function (array $q) use ($answerKey, $visibility) {
                // Choice: correct_indexes + options; ordering: options + correct_order;
                // matching: options + matches; short answer: model_answer.
                $q = [...$q, ...($answerKey[$q['id']] ?? ['correct_indexes' => [], 'options' => []])];
                if ($visibility === 'answers') {
                    $q['explanation'] = '';
                }

                return $q;
            }, $module['questionResults']);

            return $module;
        }, $moduleResults);
    }
}
