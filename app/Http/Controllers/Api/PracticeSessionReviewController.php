<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PracticeSessionResource;
use App\Models\PracticeSession;
use App\Models\PracticeSessionComment;
use App\Models\PracticeSessionRereview;
use App\Models\PracticeSessionReview;
use App\Services\AttemptFinalizer;
use App\Services\Notifier;
use App\Services\RoadmapProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

// MG2 / R7: manager Review Queue. Managers accept or adjust the AI score per
// scorecard criterion, comment on transcript lines and send feedback; brokers
// read the review in their call report and can ask for a re-review.
class PracticeSessionReviewController extends Controller
{
    /** Manager: calls waiting for review + re-review requests. */
    public function queue(Request $request): JsonResponse
    {
        $toReview = PracticeSession::query()
            ->with('user:id,name')
            ->whereDoesntHave('review')
            ->whereNotNull('transcript')
            // Compared as text so it works on both MySQL (JSON column) and SQLite.
            ->whereRaw("CAST(transcript AS CHAR) <> '[]'")
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        $rereviews = PracticeSessionRereview::query()
            ->with(['user:id,name', 'practiceSession.user:id,name', 'practiceSession.review'])
            ->orderByRaw("case when status = 'open' then 0 else 1 end")
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'data' => [
                'to_review' => PracticeSessionResource::collection($toReview)->resolve($request),
                'rereviews' => $rereviews->map(fn (PracticeSessionRereview $r): array => [
                    ...self::rereviewArray($r),
                    'session' => (new PracticeSessionResource($r->practiceSession))->resolve($request),
                    'review' => self::reviewArray($r->practiceSession->review),
                ])->values(),
                'counts' => [
                    'to_review' => $toReview->count(),
                    'rereviews_open' => $rereviews->where('status', 'open')->count(),
                ],
            ],
        ]);
    }

    /** Owner or manager: the manager review, line comments and re-review requests of one call. */
    public function show(Request $request, PracticeSession $practice_session): JsonResponse
    {
        $this->authorizeOwnerOrManager($request, $practice_session);

        return response()->json(['data' => $this->reviewPayload($practice_session)]);
    }

    /** Manager: save the review (per-criterion decisions, final score, feedback). */
    public function store(Request $request, PracticeSession $practice_session): JsonResponse
    {
        $validated = $request->validate([
            'criteria' => ['nullable', 'array'],
            'criteria.*.name' => ['required', 'string', 'max:100'],
            'criteria.*.status' => ['required', Rule::in(['accepted', 'adjusted'])],
            'criteria.*.final_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'criteria.*.reason' => ['nullable', 'string', 'max:1000'],
            'final_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'feedback' => ['nullable', 'string', 'max:5000'],
        ]);

        $aiScores = self::aiCriteria($practice_session)->pluck('ai_score', 'name');

        $criteria = collect($validated['criteria'] ?? [])
            ->filter(fn (array $c): bool => $aiScores->has($c['name']))
            ->map(function (array $c) use ($aiScores): array {
                $ai = (int) $aiScores[$c['name']];
                $adjusted = $c['status'] === 'adjusted' && isset($c['final_score']);

                return [
                    'name' => $c['name'],
                    'ai_score' => $ai,
                    'final_score' => $adjusted ? (int) $c['final_score'] : $ai,
                    'status' => $adjusted ? 'adjusted' : 'accepted',
                    'reason' => $adjusted ? ($c['reason'] ?? null) : null,
                ];
            })
            ->values();

        $finalScore = self::finalScore($practice_session, $criteria, $validated['final_score'] ?? null);

        if ($finalScore === null) {
            abort(422, 'Give the call a score (0–100).');
        }

        PracticeSessionReview::updateOrCreate(
            ['practice_session_id' => $practice_session->id],
            [
                'reviewer_id' => $request->user()->id,
                'final_score' => $finalScore,
                'criteria' => $criteria->all(),
                'feedback' => $validated['feedback'] ?? null,
                'reviewed_at' => now(),
            ]
        );

        $practice_session->score = $finalScore;
        if (filled($validated['feedback'] ?? null)) {
            $practice_session->feedback = $validated['feedback'];
        }
        $practice_session->save();

        // MG8: the reviewed score can unlock a score-gated roadmap step.
        app(RoadmapProgress::class)->completeForSession($practice_session);
        // R4: a test attempt waiting for this call's score can now be finalized.
        app(AttemptFinalizer::class)->finalizeForSession($practice_session);

        // R12: tell the broker their review is ready (bell + email).
        Notifier::reviewSent($practice_session, $request->user(), $finalScore);

        return response()->json([
            'data' => $this->reviewPayload($practice_session->refresh()),
            'message' => 'Review sent.',
        ]);
    }

    /** Manager: comment on one transcript line. */
    public function comment(Request $request, PracticeSession $practice_session): JsonResponse
    {
        $validated = $request->validate([
            'turn_index' => ['required', 'integer', 'min:0', 'max:'.max(0, count($practice_session->transcript ?? []) - 1)],
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $comment = PracticeSessionComment::create([
            'practice_session_id' => $practice_session->id,
            'author_id' => $request->user()->id,
            'turn_index' => $validated['turn_index'],
            'body' => $validated['body'],
        ]);

        return response()->json(['data' => self::commentArray($comment->load('author:id,name'))], 201);
    }

    /** Broker: ask a manager to look at the score again (own calls only). */
    public function requestRereview(Request $request, PracticeSession $practice_session): JsonResponse
    {
        abort_unless($practice_session->user_id === $request->user()->id, 403, 'You can only ask for a re-review of your own calls.');

        $validated = $request->validate([
            'criterion' => ['nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:2000'],
            'turn_index' => ['nullable', 'integer', 'min:0'],
        ]);

        abort_if(
            $practice_session->rereviews()->where('status', 'open')->exists(),
            422,
            'You already asked for a re-review of this call. Your manager will answer soon.'
        );

        $rereview = PracticeSessionRereview::create([
            ...$validated,
            'practice_session_id' => $practice_session->id,
            'user_id' => $request->user()->id,
            'status' => 'open',
        ]);

        // R12: tell the managers (bell + email).
        Notifier::rereviewRequested($rereview);

        return response()->json(['data' => self::rereviewArray($rereview->load('user:id,name'))], 201);
    }

    /** Manager: answer a re-review request (keep or change the score) with a reply. */
    public function answer(Request $request, PracticeSessionRereview $rereview): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['kept', 'changed'])],
            'new_score' => ['required_if:decision,changed', 'nullable', 'integer', 'min:0', 'max:100'],
            'reply' => ['nullable', 'string', 'max:2000'],
        ]);

        $session = $rereview->practiceSession;
        $changed = $validated['decision'] === 'changed';

        if ($changed) {
            $newScore = (int) $validated['new_score'];
            $review = $session->review;
            $criteria = collect($review?->criteria ?? self::aiCriteria($session)->map(fn (array $c): array => [
                ...$c, 'final_score' => $c['ai_score'], 'status' => 'accepted', 'reason' => null,
            ])->all());

            $matchesCriterion = $rereview->criterion !== null && $criteria->contains('name', $rereview->criterion);

            if ($matchesCriterion) {
                $criteria = $criteria->map(fn (array $c): array => $c['name'] === $rereview->criterion
                    ? [...$c, 'final_score' => $newScore, 'status' => 'adjusted', 'reason' => $validated['reply'] ?? $c['reason'] ?? null]
                    : $c)->values();
                $finalScore = self::finalScore($session, $criteria, $newScore) ?? $newScore;
            } else {
                $finalScore = $newScore;
            }

            PracticeSessionReview::updateOrCreate(
                ['practice_session_id' => $session->id],
                [
                    'reviewer_id' => $review?->reviewer_id ?? $request->user()->id,
                    'final_score' => $finalScore,
                    'criteria' => $criteria->all(),
                    'feedback' => $review?->feedback,
                    'reviewed_at' => $review?->reviewed_at ?? now(),
                ]
            );

            $session->score = $finalScore;
            $session->save();

            // MG8: a changed score can unlock a score-gated roadmap step.
            app(RoadmapProgress::class)->completeForSession($session);
            // R4: a test attempt waiting for this call's score can now be finalized.
            app(AttemptFinalizer::class)->finalizeForSession($session);
        }

        $rereview->fill([
            'status' => 'answered',
            'decision' => $validated['decision'],
            'new_score' => $changed ? (int) $validated['new_score'] : null,
            'reply' => $validated['reply'] ?? null,
            'answered_by' => $request->user()->id,
            'answered_at' => now(),
        ])->save();

        // R12: tell the broker their re-review was answered (bell + email).
        Notifier::rereviewAnswered($rereview, $request->user());

        return response()->json([
            'data' => self::rereviewArray($rereview->load('user:id,name')),
            'message' => 'Reply sent.',
        ]);
    }

    /**
     * Per-criterion AI score = share of passed items in each scorecard category.
     *
     * @return Collection<int, array{name: string, ai_score: int}>
     */
    public static function aiCriteria(PracticeSession $session): Collection
    {
        return collect($session->ai_report['scorecard'] ?? [])
            ->filter(fn ($c): bool => is_array($c) && filled($c['name'] ?? null) && ! empty($c['items']))
            ->map(function (array $c): array {
                $items = collect($c['items']);

                return [
                    'name' => (string) $c['name'],
                    'ai_score' => (int) round($items->where('passed', true)->count() / max(1, $items->count()) * 100),
                ];
            })
            ->values();
    }

    /**
     * Final score: the AI's overall score moved by the average criterion change,
     * so accepting everything keeps the AI score. Without an AI score, the
     * manager's own score (or the criteria average) is used.
     *
     * @param  Collection<int, array<string, mixed>>  $criteria
     */
    private static function finalScore(PracticeSession $session, Collection $criteria, ?int $given): ?int
    {
        $ai = $session->ai_report['score'] ?? null;

        if ($criteria->isNotEmpty()) {
            $delta = $criteria->avg(fn (array $c): int => (int) $c['final_score'] - (int) $c['ai_score']);
            $base = is_numeric($ai) ? (int) $ai : (int) round($criteria->avg('ai_score'));

            return max(0, min(100, (int) round($base + $delta)));
        }

        return $given ?? (is_numeric($ai) ? (int) $ai : null);
    }

    private function authorizeOwnerOrManager(Request $request, PracticeSession $session): void
    {
        abort_unless(
            $request->user()->isManager() || $session->user_id === $request->user()->id,
            403,
            'You can only see reviews of your own calls.'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(PracticeSession $session): array
    {
        $session->load(['review.reviewer:id,name', 'comments.author:id,name', 'rereviews.user:id,name']);

        return [
            'session_id' => $session->id,
            'ai_score' => is_numeric($session->ai_report['score'] ?? null) ? (int) $session->ai_report['score'] : null,
            'ai_criteria' => self::aiCriteria($session),
            'review' => self::reviewArray($session->review),
            'comments' => $session->comments->map(fn (PracticeSessionComment $c): array => self::commentArray($c))->values(),
            'rereviews' => $session->rereviews->map(fn (PracticeSessionRereview $r): array => self::rereviewArray($r))->values(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function reviewArray(?PracticeSessionReview $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'id' => $review->id,
            'final_score' => $review->final_score,
            'criteria' => $review->criteria ?? [],
            'feedback' => $review->feedback,
            'reviewer_name' => $review->reviewer?->name,
            'reviewed_at' => $review->reviewed_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function commentArray(PracticeSessionComment $comment): array
    {
        return [
            'id' => $comment->id,
            'turn_index' => $comment->turn_index,
            'body' => $comment->body,
            'author_name' => $comment->author?->name,
            'created_at' => $comment->created_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function rereviewArray(PracticeSessionRereview $rereview): array
    {
        return [
            'id' => $rereview->id,
            'session_id' => $rereview->practice_session_id,
            'user_name' => $rereview->user?->name,
            'criterion' => $rereview->criterion,
            'message' => $rereview->message,
            'turn_index' => $rereview->turn_index,
            'status' => $rereview->status,
            'decision' => $rereview->decision,
            'new_score' => $rereview->new_score,
            'reply' => $rereview->reply,
            'answered_at' => $rereview->answered_at?->toISOString(),
            'created_at' => $rereview->created_at?->toISOString(),
        ];
    }
}
