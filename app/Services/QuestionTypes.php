<?php

namespace App\Services;

/**
 * R6: the written question types and the shape of their stored `options`.
 *
 * - mcq / multi_select / true_false: [{label, text, is_correct}] (unchanged MCQ shape;
 *   true_false is exactly "True" and "False" with one correct)
 * - ordering: [{label, text, is_correct: true}] stored in the CORRECT order
 * - matching: [{label, text (left side), match (right side), is_correct: true}]
 * - short_answer: [] — graded by the manager, so it's pending and left out of the score
 * - roleplay_call (R4): [] — an AI voice call inside the test. settings =
 *   {simulation_id, min_score, weight_pct, attempts, hold_for_review, pause_timer};
 *   the answer is the practice_session_id of the call (see roleplaySettings()).
 *
 * Learners never receive the correct order or pairs. Ordering items and matching
 * answers are sent shuffled with an opaque `key` per item; the learner answers
 * with keys, which only the server can map back (see key()).
 */
class QuestionTypes
{
    public const CHOICE = ['mcq', 'multi_select', 'true_false'];

    public const ORDERING = 'ordering';

    public const MATCHING = 'matching';

    public const SHORT_ANSWER = 'short_answer';

    public const ROLEPLAY_CALL = 'roleplay_call';

    /** Every type a question may be saved with (ai_voice / ai_chat are older, unused values). */
    public const ALL = ['mcq', 'multi_select', 'true_false', 'ordering', 'matching', 'short_answer', 'roleplay_call', 'ai_voice', 'ai_chat'];

    /** Types the grader scores. */
    public const GRADED = ['mcq', 'multi_select', 'true_false', 'ordering', 'matching', 'short_answer', 'roleplay_call'];

    public static function normalize(?string $type): string
    {
        return strtolower(trim((string) $type));
    }

    public static function isChoice(?string $type): bool
    {
        return in_array(self::normalize($type), self::CHOICE, true);
    }

    public static function isGraded(?string $type): bool
    {
        return in_array(self::normalize($type), self::GRADED, true);
    }

    public static function isRoleplay(?string $type): bool
    {
        return self::normalize($type) === self::ROLEPLAY_CALL;
    }

    /**
     * R4: a roleplay call question's settings with defaults filled in.
     *
     * @return array{simulation_id: ?int, min_score: int, weight_pct: int, attempts: int, hold_for_review: bool, pause_timer: bool}
     */
    public static function roleplaySettings(mixed $settings): array
    {
        $s = is_array($settings) ? $settings : [];

        return [
            'simulation_id' => isset($s['simulation_id']) && is_numeric($s['simulation_id']) ? (int) $s['simulation_id'] : null,
            'min_score' => max(0, min(100, (int) ($s['min_score'] ?? 70))),
            'weight_pct' => max(1, min(90, (int) ($s['weight_pct'] ?? 30))),
            'attempts' => ((int) ($s['attempts'] ?? 1)) === 2 ? 2 : 1,
            'hold_for_review' => (bool) ($s['hold_for_review'] ?? false),
            'pause_timer' => (bool) ($s['pause_timer'] ?? true),
        ];
    }

    /** Whether partial credit is on for an ordering / matching question. */
    public static function partial(mixed $settings): bool
    {
        return is_array($settings) && ($settings['scoring'] ?? null) === 'partial';
    }

    /**
     * Opaque, stable key for one item of a question. "item" keys are ordering
     * items / matching left sides; "match" keys are matching right sides.
     */
    public static function key(int|string $questionId, string $side, int $index): string
    {
        return substr(hash_hmac('sha256', "{$questionId}:{$side}:{$index}", (string) config('app.key')), 0, 12);
    }

    /**
     * Options as a learner receives them: no correct flags, no order or pairs.
     *
     * @param  list<array<string, mixed>>  $options
     * @return array{options: list<array<string, mixed>>, match_choices: list<array{key: string, text: string}>|null}
     */
    public static function learnerView(int|string $questionId, string $type, array $options): array
    {
        $type = self::normalize($type);
        $options = array_values($options);

        if ($type === self::ORDERING) {
            $items = [];
            foreach ($options as $i => $option) {
                $items[] = ['key' => self::key($questionId, 'item', $i), 'text' => (string) ($option['text'] ?? '')];
            }
            shuffle($items);
            // Never hand back the stored order: reshuffle if it came out the same.
            if (count($items) > 1 && array_column($items, 'key') === array_map(fn ($i) => self::key($questionId, 'item', $i), array_keys($options))) {
                $items = array_reverse($items);
            }

            return ['options' => $items, 'match_choices' => null];
        }

        if ($type === self::MATCHING) {
            $left = [];
            $right = [];
            foreach ($options as $i => $option) {
                $left[] = ['key' => self::key($questionId, 'item', $i), 'text' => (string) ($option['text'] ?? '')];
                $right[] = ['key' => self::key($questionId, 'match', $i), 'text' => (string) ($option['match'] ?? '')];
            }
            shuffle($right);

            return ['options' => $left, 'match_choices' => $right];
        }

        if ($type === self::SHORT_ANSWER || $type === self::ROLEPLAY_CALL) {
            return ['options' => [], 'match_choices' => null];
        }

        return [
            'options' => array_map(
                fn ($option) => is_array($option) ? array_diff_key($option, ['is_correct' => true]) : $option,
                $options
            ),
            'match_choices' => null,
        ];
    }
}
