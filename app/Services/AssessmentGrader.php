<?php

namespace App\Services;

use App\Models\Assessment;
use App\Models\PracticeSession;
use App\Models\Question;

/**
 * Grades an assessment attempt on the server, against the correct answers the
 * learner never receives. Replaces the old client-side grading, which trusted
 * whatever score the browser sent.
 *
 * Rules (all from the assessment's scoring_rules):
 * - pass_pct: overall % needed to pass (default 70). Also the bar for each module.
 * - hard_gates_enabled: a wrong answer on a "required to pass" question fails
 *   that module, and the attempt can't pass overall.
 * - junior_max_score / senior_min_score: tier bands (Junior / Mid-Tier / Senior).
 * - block_gates (MG3): block position => minimum %. A block below its gate
 *   fails, and the attempt can't pass overall.
 * - pass_bonus_xp (MG3): extra XP added when the attempt passes.
 */
class AssessmentGrader
{
    public const DEFAULT_PASS_PCT = 70;

    public const DEFAULT_TIME_LIMIT_MINUTES = 15;

    /**
     * @param  array<int|string, mixed>  $answers  question id => the learner's answer. Shape per type:
     *                                             mcq / true_false: option index (0-based); multi_select: list of indexes;
     *                                             ordering: list of item keys in the learner's order;
     *                                             matching: {item key: match key}; short_answer: text.
     * @param  list<int>|null  $questionIds  R6 pools: only these questions are graded (the attempt's draw).
     * @return array{
     *     score_pct: int, total_correct: int, total_questions: int, xp_earned: int,
     *     passed: bool, tier: string, pass_pct: int, pending_review: int, module_results: list<array<string, mixed>>,
     *     answer_key: array<string, array<string, mixed>>
     * }
     */
    public function grade(Assessment $assessment, array $answers, ?array $questionIds = null): array
    {
        $rules = self::rules($assessment);
        $assessment->loadMissing('blocks.questions');
        $drawn = $questionIds === null ? null : array_flip(array_map('intval', $questionIds));

        $blocks = [];
        foreach ($assessment->blocks as $block) {
            if ($block->enabled === false) {
                continue; // switched off in the builder (Q2)
            }

            $questions = $block->questions
                ->filter(fn ($q) => QuestionTypes::isGraded($q->type))
                ->filter(fn ($q) => $drawn === null || isset($drawn[(int) $q->id]));

            if ($questions->isNotEmpty()) {
                $blocks[] = [$block, $questions];
            }
        }

        // R4: a roleplay call counts weight_pct % of the whole attempt.
        $weights = self::roleplayWeights(collect($blocks)->flatMap(fn ($b) => $b[1]->all())->all());

        $moduleResults = [];
        // Correct answers per question. Returned for the attempt response only
        // (never stored with the attempt); the controller decides what to reveal.
        $answerKey = [];

        foreach ($blocks as [$block, $questions]) {
            $questionResults = [];

            foreach ($questions as $question) {
                $type = QuestionTypes::normalize($question->type);
                $answer = $answers[$question->id] ?? $answers[(string) $question->id] ?? null;
                [$result, $key] = $this->gradeQuestion($question, $type, $answer);

                $weight = $weights[(int) $question->id] ?? 1.0;
                // A blank written answer is left out of the score (as before R4).
                if ($type === QuestionTypes::SHORT_ANSWER && ($result['response'] ?? '') === '') {
                    $weight = 0.0;
                    $result = [...$result, 'pending' => false, 'skipped' => true];
                }

                $questionResults[] = self::withPoints([
                    'id' => (string) $question->id,
                    'type' => $type,
                    'label' => preg_replace('/^\d+\.\s*/', '', (string) $question->question_text),
                    'explanation' => (string) ($question->answer_explanation ?? ''),
                    'required' => (bool) $question->required_to_pass_block,
                    'reward' => (int) $question->reward,
                    'weight' => $weight,
                    ...$result,
                ]);
                $answerKey[(string) $question->id] = $key;
            }

            $moduleResults[] = [
                'blockId' => (string) $block->id,
                'blockTitle' => (string) $block->title,
                'totalPossibleXp' => (int) $questions->sum('reward'),
                'blockGatePct' => $rules['block_gates'][(string) $block->position] ?? null,
                'questionResults' => $questionResults,
            ];
        }

        return [...self::summarize($moduleResults, $rules), 'answer_key' => $answerKey];
    }

    /**
     * The answer key for the given questions (same shape as grade()'s
     * answer_key), for showing a stored attempt again later. Never stored.
     *
     * @param  list<int|string>  $questionIds
     * @return array<string, array<string, mixed>>
     */
    public function answerKey(Assessment $assessment, array $questionIds): array
    {
        $wanted = array_flip(array_map('strval', $questionIds));
        $assessment->loadMissing('blocks.questions');
        $key = [];
        foreach ($assessment->blocks as $block) {
            foreach ($block->questions as $question) {
                if (! isset($wanted[(string) $question->id])) {
                    continue;
                }
                $type = QuestionTypes::normalize($question->type);
                $key[(string) $question->id] = $type === QuestionTypes::ROLEPLAY_CALL
                    ? ['min_score' => QuestionTypes::roleplaySettings($question->settings)['min_score']]
                    : $this->gradeQuestion($question, $type, null)[1];
            }
        }

        return $key;
    }

    /**
     * R4: rebuilds module and attempt totals from the per-question results
     * (each with credit 0..1, weight, required, pending). Pending questions
     * (a call awaiting its score, a written answer awaiting the manager) are
     * left out of the score, and the attempt can't pass until none are left.
     *
     * @param  list<array<string, mixed>>  $moduleResults
     * @param  array<string, mixed>  $rules  see rules()
     * @return array{score_pct: int, total_correct: int, total_questions: int, xp_earned: int, bonus_xp: int,
     *     passed: bool, tier: string, pass_pct: int, pending_review: int, module_results: list<array<string, mixed>>}
     */
    public static function summarize(array $moduleResults, array $rules): array
    {
        $totalWeight = 0.0;
        $totalPoints = 0.0;
        $pendingReview = 0;

        foreach ($moduleResults as $m => $module) {
            $questionResults = array_map(fn (array $q) => self::withPoints($q), $module['questionResults'] ?? []);
            $scored = array_values(array_filter($questionResults, fn ($q) => ! ($q['pending'] ?? false) && (float) ($q['weight'] ?? 1) > 0));
            $pending = count(array_filter($questionResults, fn ($q) => (bool) ($q['pending'] ?? false)));
            $weight = array_sum(array_map(fn ($q) => (float) ($q['weight'] ?? 1), $scored));
            $points = array_sum(array_map(fn ($q) => (float) $q['points'], $scored));
            $scorePct = $weight > 0 ? (int) round($points / $weight * 100) : 0;
            $gateFailed = (bool) $rules['hard_gates_enabled'] && collect($scored)
                ->contains(fn ($q) => ($q['required'] ?? false) && ! ($q['correct'] ?? false));
            $blockGate = $module['blockGatePct'] ?? null;
            $belowBlockGate = $blockGate !== null && $scored !== [] && $scorePct < $blockGate;

            $moduleResults[$m] = [
                ...$module,
                'correct' => count(array_filter($scored, fn ($q) => (bool) ($q['correct'] ?? false))),
                'total' => count($scored),
                'weight' => round($weight, 4),
                'points' => round($points, 4),
                'scorePct' => $scorePct,
                'xpEarned' => array_sum(array_column($questionResults, 'xp')),
                'gateFailed' => $gateFailed,
                'belowBlockGate' => $belowBlockGate,
                'pending' => $pending,
                'passed' => ($scored === [] || $scorePct >= $rules['pass_pct']) && ! $gateFailed && ! $belowBlockGate,
                'questionResults' => $questionResults,
            ];
            $totalWeight += $weight;
            $totalPoints += $points;
            $pendingReview += $pending;
        }

        $scorePct = $totalWeight > 0 ? (int) round($totalPoints / $totalWeight * 100) : 0;
        $anyGateFailed = in_array(true, array_column($moduleResults, 'gateFailed'), true)
            || in_array(true, array_column($moduleResults, 'belowBlockGate'), true);
        $passed = $pendingReview === 0 && $scorePct >= $rules['pass_pct'] && ! $anyGateFailed;
        $bonusXp = $passed ? (int) $rules['pass_bonus_xp'] : 0;

        return [
            'score_pct' => $scorePct,
            'total_correct' => array_sum(array_column($moduleResults, 'correct')),
            'total_questions' => array_sum(array_column($moduleResults, 'total')),
            'xp_earned' => array_sum(array_column($moduleResults, 'xpEarned')) + $bonusXp,
            'bonus_xp' => $bonusXp,
            'passed' => $passed,
            'tier' => self::tier($scorePct, $rules),
            'pass_pct' => (int) $rules['pass_pct'],
            'pending_review' => $pendingReview,
            'module_results' => array_values($moduleResults),
        ];
    }

    /**
     * R4: weight of each roleplay call question, so that once graded each call
     * counts exactly its weight_pct % of the attempt: n × s / (100 − S), with n
     * the other graded questions (each weight 1) and S all calls' weight_pct.
     *
     * @param  list<Question>  $questions  the attempt's graded questions
     * @return array<int, float> question id => weight (roleplay questions only)
     */
    public static function roleplayWeights(array $questions): array
    {
        $calls = array_values(array_filter($questions, fn ($q) => QuestionTypes::isRoleplay($q->type)));
        if ($calls === []) {
            return [];
        }

        $n = count($questions) - count($calls);
        $shares = array_map(fn ($q) => QuestionTypes::roleplaySettings($q->settings)['weight_pct'], $calls);
        $total = min(95, array_sum($shares));

        $weights = [];
        foreach ($calls as $i => $q) {
            $weights[(int) $q->id] = $n > 0 ? round($n * $shares[$i] / (100 - $total), 6) : 1.0;
        }

        return $weights;
    }

    /**
     * R4: a roleplay call's result from its practice session(s). The best
     * score of the submitted calls counts: full points at or above min_score,
     * zero below. With hold_for_review only the manager's final score counts.
     * Pending while any submitted call has no usable score yet.
     *
     * @param  list<int>  $sessionIds
     * @return array<string, mixed>
     */
    public static function roleplayResult(Question $question, array $sessionIds): array
    {
        $settings = QuestionTypes::roleplaySettings($question->settings);
        $base = [
            'session_ids' => array_values(array_map('intval', $sessionIds)),
            'min_score' => $settings['min_score'],
            'weight_pct' => $settings['weight_pct'],
            'hold_for_review' => $settings['hold_for_review'],
            'chosen' => [],
        ];

        if ($sessionIds === []) {
            return [...$base, 'correct' => false, 'credit' => 0.0, 'pending' => false, 'skipped' => true, 'session_id' => null, 'call_score' => null];
        }

        $scores = PracticeSession::query()->with('review')->whereIn('id', $sessionIds)->get()
            ->mapWithKeys(function (PracticeSession $session) use ($settings) {
                $managerScore = $session->review?->final_score;
                $score = $settings['hold_for_review'] ? $managerScore : ($managerScore ?? $session->score);

                return [(int) $session->id => $score === null ? null : (int) $score];
            });

        if ($scores->count() < count($sessionIds) || $scores->contains(fn ($score) => $score === null)) {
            return [...$base, 'correct' => false, 'credit' => 0.0, 'pending' => true, 'session_id' => null, 'call_score' => null];
        }

        $best = $scores->sortDesc()->keys()->first();
        $callScore = (int) $scores[$best];
        $passed = $callScore >= $settings['min_score'];

        return [...$base, 'correct' => $passed, 'credit' => $passed ? 1.0 : 0.0, 'pending' => false, 'session_id' => (int) $best, 'call_score' => $callScore];
    }

    /**
     * Fills a question result's points (credit × weight) and XP (credit ×
     * reward); both 0 while pending.
     *
     * @param  array<string, mixed>  $q
     * @return array<string, mixed>
     */
    public static function withPoints(array $q): array
    {
        $pending = (bool) ($q['pending'] ?? false);
        $credit = (float) ($q['credit'] ?? 0);
        $q['weight'] = (float) ($q['weight'] ?? 1);
        $q['points'] = $pending ? 0.0 : round($credit * $q['weight'], 6);
        if (array_key_exists('reward', $q)) {
            $q['xp'] = $pending ? 0 : (int) round($credit * (int) $q['reward']);
        }

        return $q;
    }

    /**
     * Grades one question. Returns the stored result fields (correct, credit
     * 0..1, chosen, and pending/response for short answers) and the answer key.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function gradeQuestion(Question $question, string $type, mixed $answer): array
    {
        $options = array_values($question->options ?? []);
        $partial = QuestionTypes::partial($question->settings);

        if ($type === QuestionTypes::SHORT_ANSWER) {
            $response = is_string($answer) ? trim($answer) : '';

            return [
                // Always left out of the score (the manager grades it), even when blank.
                ['correct' => false, 'credit' => 0.0, 'pending' => true, 'response' => $response, 'chosen' => []],
                ['model_answer' => (string) ($question->settings['model_answer'] ?? '')],
            ];
        }

        if ($type === QuestionTypes::ROLEPLAY_CALL) {
            $ids = collect(is_array($answer) ? $answer : [$answer])
                ->filter(fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)))
                ->map(fn ($id) => (int) $id)->unique()->values()->all();

            return [self::roleplayResult($question, $ids), ['min_score' => QuestionTypes::roleplaySettings($question->settings)['min_score']]];
        }

        if ($type === QuestionTypes::ORDERING) {
            $keyToIndex = [];
            foreach ($options as $i => $_) {
                $keyToIndex[QuestionTypes::key($question->id, 'item', $i)] = $i;
            }
            // Learner's order as original item indexes (unknown keys dropped).
            $chosen = collect(is_array($answer) ? array_values($answer) : [])
                ->map(fn ($k) => is_string($k) ? ($keyToIndex[$k] ?? null) : null)
                ->filter(fn ($i) => $i !== null)->unique()->values()->all();
            $n = count($options);
            $inPlace = 0;
            for ($i = 0; $i < $n; $i++) {
                if (($chosen[$i] ?? null) === $i) {
                    $inPlace++;
                }
            }
            $isCorrect = $n > 0 && $inPlace === $n;
            $credit = $isCorrect ? 1.0 : ($partial && $n > 0 ? $inPlace / $n : 0.0);
            $texts = array_map(fn ($o) => (string) ($o['text'] ?? ''), $options);

            return [
                ['correct' => $isCorrect, 'credit' => $credit, 'chosen' => $chosen],
                ['options' => $texts, 'correct_order' => array_keys($texts)],
            ];
        }

        if ($type === QuestionTypes::MATCHING) {
            $matchByKey = [];
            foreach ($options as $i => $option) {
                $matchByKey[QuestionTypes::key($question->id, 'match', $i)] = $i;
            }
            $given = is_array($answer) ? $answer : [];
            $chosen = [];
            $right = 0;
            foreach ($options as $i => $option) {
                $picked = $given[QuestionTypes::key($question->id, 'item', $i)] ?? null;
                $pickedIndex = is_string($picked) ? ($matchByKey[$picked] ?? null) : null;
                $chosen[] = $pickedIndex;
                // Compared by text, so two identical right-hand answers are interchangeable.
                if ($pickedIndex !== null && $this->same($options[$pickedIndex]['match'] ?? '', $option['match'] ?? '')) {
                    $right++;
                }
            }
            $n = count($options);
            $isCorrect = $n > 0 && $right === $n;
            $credit = $isCorrect ? 1.0 : ($partial && $n > 0 ? $right / $n : 0.0);

            return [
                ['correct' => $isCorrect, 'credit' => $credit, 'chosen' => $chosen],
                ['options' => array_map(fn ($o) => (string) ($o['text'] ?? ''), $options), 'matches' => array_map(fn ($o) => (string) ($o['match'] ?? ''), $options)],
            ];
        }

        // mcq / multi_select / true_false
        $chosenIndexes = collect(is_array($answer) ? $answer : [$answer])
            ->filter(fn ($i) => is_int($i) || (is_string($i) && ctype_digit($i)))
            ->map(fn ($i) => (int) $i)
            ->unique()->sort()->values()->all();
        $correctIndexes = collect($options)
            ->filter(fn ($o) => (bool) ($o['is_correct'] ?? false))
            ->keys()->sort()->values()->all();

        // Correct only when the learner picked exactly the correct option(s).
        $isCorrect = $chosenIndexes !== [] && $chosenIndexes === $correctIndexes;

        return [
            ['correct' => $isCorrect, 'credit' => $isCorrect ? 1.0 : 0.0, 'chosen' => $chosenIndexes],
            ['correct_indexes' => $correctIndexes, 'options' => array_map(fn ($o) => (string) ($o['text'] ?? $o['label'] ?? ''), $options)],
        ];
    }

    private function same(mixed $a, mixed $b): bool
    {
        return mb_strtolower(trim((string) $a)) === mb_strtolower(trim((string) $b));
    }

    /**
     * Scoring rules with defaults filled in, for assessments saved before
     * pass_pct / time_limit_minutes existed.
     *
     * @return array{pass_pct: int, time_limit_minutes: int, junior_max_score: int, senior_min_score: int, hard_gates_enabled: bool, block_gates: array<string, int>, pass_bonus_xp: int, results_visibility: string, certificate_enabled: bool}
     */
    public static function rules(Assessment $assessment): array
    {
        $raw = $assessment->scoring_rules ?? [];

        return [
            'pass_pct' => (int) ($raw['pass_pct'] ?? self::DEFAULT_PASS_PCT),
            'time_limit_minutes' => (int) ($raw['time_limit_minutes'] ?? self::DEFAULT_TIME_LIMIT_MINUTES),
            'junior_max_score' => (int) ($raw['junior_max_score'] ?? 59),
            'senior_min_score' => (int) ($raw['senior_min_score'] ?? 85),
            'hard_gates_enabled' => (bool) ($raw['hard_gates_enabled'] ?? true),
            'block_gates' => collect(is_array($raw['block_gates'] ?? null) ? $raw['block_gates'] : [])
                ->filter(fn ($pct) => is_numeric($pct) && (int) $pct > 0)
                ->mapWithKeys(fn ($pct, $position) => [(string) $position => min(100, (int) $pct)])
                ->all(),
            'pass_bonus_xp' => max(0, (int) ($raw['pass_bonus_xp'] ?? 0)),
            'results_visibility' => in_array($raw['results_visibility'] ?? null, ['full', 'answers', 'score'], true)
                ? $raw['results_visibility'] : 'full',
            'certificate_enabled' => (bool) ($raw['certificate_enabled'] ?? true),
        ];
    }

    /**
     * @param  array{junior_max_score: int, senior_min_score: int}  $rules
     */
    private static function tier(int $scorePct, array $rules): string
    {
        if ($scorePct >= $rules['senior_min_score']) {
            return 'Senior';
        }

        if ($scorePct <= $rules['junior_max_score']) {
            return 'Junior';
        }

        return 'Mid-Tier';
    }
}
