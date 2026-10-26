<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\AssessmentAssignment;
use App\Models\AssessmentAttempt;
use App\Models\RoadmapNode;
use App\Services\AssessmentGrader;
use App\Services\QuestionTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * MG3 / R2: real usage numbers for the manager's Assessment library and the
 * builder's Usage tab, worked out from graded attempts (replaces the old
 * client-set usage_analytics).
 */
class AssessmentUsageController extends Controller
{
    /** Below this % answered correctly, a question is flagged "Check wording". */
    public const CHECK_WORDING_BELOW_PCT = 40;

    /**
     * Per-assessment counts for the library table and the delete pop-up.
     */
    public function index(): JsonResponse
    {
        $attempts = AssessmentAttempt::query()
            ->selectRaw('assessment_id, count(*) as total')
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');
        $passes = AssessmentAttempt::query()
            ->where('passed', true)
            ->selectRaw('assessment_id, count(*) as total')
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');

        $roadmaps = RoadmapNode::query()
            ->whereNotNull('assessment_id')
            ->selectRaw('assessment_id, count(distinct roadmap_id) as total')
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');

        $assigned = AssessmentAssignment::query()
            ->selectRaw('assessment_id, count(distinct user_id) as total')
            ->groupBy('assessment_id')
            ->pluck('total', 'assessment_id');

        $data = Assessment::query()->pluck('id')->mapWithKeys(function (int $id) use ($attempts, $passes, $roadmaps, $assigned): array {
            $count = (int) ($attempts[$id] ?? 0);
            $passed = (int) ($passes[$id] ?? 0);

            return [(string) $id => [
                'attempts' => $count,
                'pass_rate_pct' => $count > 0 ? (int) round($passed / $count * 100) : null,
                'roadmaps_count' => (int) ($roadmaps[$id] ?? 0),
                'assigned_brokers_count' => (int) ($assigned[$id] ?? 0),
            ]];
        });

        return response()->json(['data' => $data->isEmpty() ? new \stdClass : $data]);
    }

    /**
     * Usage for one assessment. ?period=30d (default) or all.
     */
    public function show(Request $request, Assessment $assessment): JsonResponse
    {
        $period = $request->query('period') === 'all' ? 'all' : '30d';
        $rules = AssessmentGrader::rules($assessment);
        $assessment->loadMissing('blocks.questions');

        $allAttempts = AssessmentAttempt::query()
            ->where('assessment_id', $assessment->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Each broker's first-ever attempt, so "first-attempt pass rate" means the same in both periods.
        $firstAttemptIds = $allAttempts->groupBy('user_id')->map(fn (Collection $rows) => $rows->first()->id)->values()->all();

        $attempts = $period === 'all'
            ? $allAttempts
            : $allAttempts->filter(fn (AssessmentAttempt $a) => $a->created_at !== null && $a->created_at->gte(now()->subDays(30)))->values();

        $count = $attempts->count();
        $firstAttempts = $attempts->filter(fn (AssessmentAttempt $a) => in_array($a->id, $firstAttemptIds, true));
        $timed = $attempts->filter(fn (AssessmentAttempt $a) => (int) $a->elapsed_seconds > 0);

        return response()->json(['data' => [
            'period' => $period,
            'attempts' => $count,
            'brokers' => $attempts->pluck('user_id')->unique()->count(),
            'pass_rate_pct' => $this->pct($attempts->where('passed', true)->count(), $count),
            'first_attempt_pass_rate_pct' => $this->pct($firstAttempts->where('passed', true)->count(), $firstAttempts->count()),
            'avg_score_pct' => $count > 0 ? (int) round($attempts->avg('score_pct')) : null,
            'avg_seconds' => $timed->isNotEmpty() ? (int) round($timed->avg('elapsed_seconds')) : null,
            'time_limit_minutes' => $rules['time_limit_minutes'],
            // Design "Tiers earned": failed attempts first, then the tier of each pass.
            'not_passed' => $attempts->where('passed', false)->count(),
            'tiers' => collect(['Junior', 'Mid-Tier', 'Senior'])
                ->mapWithKeys(fn (string $tier) => [$tier => $attempts->where('passed', true)->where('tier', $tier)->count()])
                ->all(),
            'questions' => $this->questionStats($assessment, $attempts),
        ]]);
    }

    /**
     * @param  Collection<int, AssessmentAttempt>  $attempts
     * @return list<array<string, mixed>>
     */
    private function questionStats(Assessment $assessment, Collection $attempts): array
    {
        // Tally every graded answer by question text: saving the builder
        // recreates questions with new ids, so the text is the stable key.
        $tally = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->module_results ?? [] as $module) {
                foreach ($module['questionResults'] ?? [] as $result) {
                    $id = $this->questionKey((string) ($result['label'] ?? ''));
                    // R6: short answers waiting for review aren't graded yet.
                    if ($id === '' || ! empty($result['pending'])) {
                        continue;
                    }
                    $tally[$id] ??= ['answered' => 0, 'correct' => 0, 'wrong_picks' => [], 'wrong_tracked' => 0];
                    $tally[$id]['answered']++;
                    if (! empty($result['correct'])) {
                        $tally[$id]['correct']++;

                        continue;
                    }
                    // `chosen` is only stored since MG3; older attempts can't say which wrong answer was picked.
                    // Wrong picks are only meaningful for choice questions (old results have no type).
                    if (QuestionTypes::isChoice($result['type'] ?? 'mcq') && array_key_exists('chosen', $result) && is_array($result['chosen'])) {
                        $tally[$id]['wrong_tracked']++;
                        foreach ($result['chosen'] as $index) {
                            $key = (int) $index;
                            $tally[$id]['wrong_picks'][$key] = ($tally[$id]['wrong_picks'][$key] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        $rows = [];
        foreach ($assessment->blocks as $block) {
            foreach ($block->questions as $position => $question) {
                if (! QuestionTypes::isGraded($question->type) || QuestionTypes::normalize($question->type) === QuestionTypes::SHORT_ANSWER) {
                    continue;
                }
                $stats = $tally[$this->questionKey((string) $question->question_text)] ?? ['answered' => 0, 'correct' => 0, 'wrong_picks' => [], 'wrong_tracked' => 0];
                $options = array_values($question->options ?? []);
                $mostPicked = null;
                if ($stats['wrong_picks'] !== [] && QuestionTypes::isChoice($question->type)) {
                    // Only wrong options count as "most-picked wrong answer".
                    $wrongOnly = array_filter(
                        $stats['wrong_picks'],
                        fn ($n, $index) => isset($options[$index]) && ! ($options[$index]['is_correct'] ?? false),
                        ARRAY_FILTER_USE_BOTH,
                    );
                    if ($wrongOnly !== []) {
                        arsort($wrongOnly);
                        $index = array_key_first($wrongOnly);
                        $mostPicked = [
                            'index' => $index,
                            'text' => (string) ($options[$index]['text'] ?? ''),
                            'pct' => $this->pct($wrongOnly[$index], $stats['answered']),
                        ];
                    }
                }
                $correctPct = $this->pct($stats['correct'], $stats['answered']);

                $rows[] = [
                    'id' => (string) $question->id,
                    'block_title' => (string) $block->title,
                    'number' => $position + 1,
                    'text' => preg_replace('/^\d+\.\s*/', '', (string) $question->question_text),
                    'answered' => $stats['answered'],
                    'correct_pct' => $correctPct,
                    'most_picked_wrong' => $mostPicked,
                    // Per-question time isn't tracked yet.
                    'avg_seconds' => null,
                    'check_wording' => $correctPct !== null && $correctPct < self::CHECK_WORDING_BELOW_PCT,
                ];
            }
        }

        // Hardest first; questions nobody answered go last.
        usort($rows, fn ($a, $b) => ($a['correct_pct'] ?? 101) <=> ($b['correct_pct'] ?? 101));

        return $rows;
    }

    private function questionKey(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\d+\.\s*/', '', $text)));
    }

    private function pct(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }
}
