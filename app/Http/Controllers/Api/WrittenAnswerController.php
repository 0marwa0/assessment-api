<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssessmentAttemptResource;
use App\Models\AssessmentAttempt;
use App\Models\Question;
use App\Services\AttemptFinalizer;
use App\Services\QuestionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * R4 (manager): test questions that need a person — written answers to grade,
 * and roleplay calls whose score the attempt is waiting for.
 */
class WrittenAnswerController extends Controller
{
    /**
     * GET /written-answers?status=pending|graded|all&type=short_answer|roleplay
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,graded,all'],
            'type' => ['sometimes', 'string', 'in:short_answer,roleplay'],
        ]);
        $status = $validated['status'] ?? 'pending';
        $type = ($validated['type'] ?? 'short_answer') === 'roleplay' ? QuestionTypes::ROLEPLAY_CALL : QuestionTypes::SHORT_ANSWER;

        $attempts = AssessmentAttempt::query()
            ->with(['user:id,name,email', 'assessment:id,title'])
            // MySQL stores JSON as `"type": "x"`, SQLite as `"type":"x"`.
            ->where(fn ($q) => $q->where('module_results', 'like', '%"type":"'.$type.'"%')
                ->orWhere('module_results', 'like', '%"type": "'.$type.'"%'))
            ->when($status === 'pending', fn ($q) => $q->where('pending_review', '>', 0))
            ->latest('id')
            ->limit(500)
            ->get();

        $rows = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->module_results ?? [] as $module) {
                foreach ($module['questionResults'] ?? [] as $q) {
                    if (($q['type'] ?? null) !== $type) {
                        continue;
                    }
                    $pending = (bool) ($q['pending'] ?? false);
                    if ($type === QuestionTypes::SHORT_ANSWER && ! $pending && ! isset($q['decision'])) {
                        continue; // blank answer, never graded: nothing to review
                    }
                    if (($status === 'pending' && ! $pending) || ($status === 'graded' && $pending)) {
                        continue;
                    }
                    $rows[] = [$attempt, $module, $q];
                }
            }
        }

        $questions = Question::query()->whereIn('id', array_map(fn ($r) => (int) $r[2]['id'], $rows))->get()->keyBy('id');

        $data = array_map(function (array $row) use ($questions, $type) {
            [$attempt, $module, $q] = $row;
            $question = $questions->get((int) $q['id']);
            $item = [
                'attempt_id' => $attempt->id,
                'assessment_id' => $attempt->assessment_id,
                'assessment_title' => $attempt->assessment?->title,
                'broker' => ['id' => $attempt->user_id, 'name' => $attempt->user?->name, 'email' => $attempt->user?->email],
                'block_title' => $module['blockTitle'] ?? null,
                'question_id' => (string) $q['id'],
                'question_text' => $question?->question_text ?? ($q['label'] ?? ''),
                'type' => $type,
                'required' => (bool) ($q['required'] ?? false),
                'pending' => (bool) ($q['pending'] ?? false),
                'credit' => (float) ($q['credit'] ?? 0),
                'submitted_at' => $attempt->created_at?->toISOString(),
                'attempt_pending_review' => (int) $attempt->pending_review,
            ];

            if ($type === QuestionTypes::SHORT_ANSWER) {
                return [
                    ...$item,
                    'model_answer' => (string) ($question?->settings['model_answer'] ?? ''),
                    'response' => (string) ($q['response'] ?? ''),
                    'decision' => $q['decision'] ?? null,
                    'history' => $q['history'] ?? [],
                ];
            }

            return [
                ...$item,
                'session_ids' => $q['session_ids'] ?? [],
                'session_id' => $q['session_id'] ?? null,
                'call_score' => $q['call_score'] ?? null,
                'min_score' => $q['min_score'] ?? null,
                'weight_pct' => $q['weight_pct'] ?? null,
                'hold_for_review' => (bool) ($q['hold_for_review'] ?? false),
                'simulation_id' => QuestionTypes::roleplaySettings($question?->settings)['simulation_id'],
            ];
        }, $rows);

        return response()->json(['data' => $data]);
    }

    /**
     * POST /assessment-attempts/{attempt}/questions/{questionId}/grade {credit: 0..1, comment?}
     */
    public function grade(Request $request, AssessmentAttempt $attempt, string $questionId, AttemptFinalizer $finalizer): AssessmentAttemptResource
    {
        $validated = $request->validate([
            'credit' => ['required', 'numeric', 'min:0', 'max:1'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        $attempt = $finalizer->gradeWrittenAnswer(
            $attempt,
            $questionId,
            (float) $validated['credit'],
            $validated['comment'] ?? null,
            $request->user(),
        );

        return (new AssessmentAttemptResource($attempt))->additional([
            'pending_review' => (int) $attempt->pending_review,
            'is_final' => (int) $attempt->pending_review === 0,
            'message' => 'Answer graded.',
        ]);
    }
}
