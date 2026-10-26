<?php

namespace App\Services;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;

/**
 * R3: weighted per-simulation scorecards.
 *
 * - builds the scorecard part of the AI call-report prompt and tool schema
 *   (custom criteria when the simulation has a scorecard, the fixed
 *   5-category rubric otherwise);
 * - turns the AI's yes/no items into a weighted overall score;
 * - drafts a scorecard with AI for the manager's Simulation Library.
 */
class SimulationScorecard
{
    /** The default rubric used when a simulation has no scorecard. */
    public const FIXED_CATEGORIES = ['Opener', 'Discovery', 'Social Proof', 'Takeaway', 'Closing'];

    /**
     * Keep only well-formed rows; null when there's no usable scorecard.
     *
     * @return list<array{name: string, weight: int}>|null
     */
    public function criteria(mixed $scorecard): ?array
    {
        if (! is_array($scorecard)) {
            return null;
        }

        $rows = collect($scorecard)
            ->filter(fn ($row): bool => is_array($row) && filled($row['name'] ?? null) && (int) ($row['weight'] ?? 0) > 0)
            ->map(fn (array $row): array => ['name' => trim((string) $row['name']), 'weight' => (int) $row['weight']])
            ->values()
            ->all();

        return $rows === [] ? null : $rows;
    }

    /**
     * The scorecard instructions for the AI report prompt.
     *
     * @param  list<array{name: string, weight: int}>|null  $criteria
     */
    public function reportPrompt(?array $criteria): string
    {
        if ($criteria === null) {
            return <<<'PROMPT'
                Score the call against this fixed rubric for the "scorecard" field. Use exactly these 5 category
                names and exactly these items per category (do not add, remove, or rename items) — mark each
                "passed" true/false based on the transcript, with a one-sentence "note" justifying the call:
                - Opener: "Used a permission-based opener?", "Referenced research on the prospect?"
                - Discovery: "Asked about the buyer's needs or preconceptions?"
                - Social Proof: "Provided relevant social proof (comps, track record, testimonials)?", "Asked if the social proof was relevant to them?"
                - Takeaway: "Reconfirmed that the time works for the prospect?", "Asked for success criteria for the next call?"
                - Closing: "Next steps agreed upon?", "Follow-up meeting or showing booked?"
                PROMPT;
        }

        $lines = collect($criteria)
            ->map(fn (array $row): string => sprintf('- "%s" (weight %d%%)', $row['name'], $row['weight']))
            ->implode("\n");

        return <<<PROMPT
            Score the call against this manager-defined weighted scorecard for the "scorecard" field. Use exactly
            these category names, in this order, one category each (do not add, remove, or rename categories):
            {$lines}
            For each category write 1–3 short yes/no check questions ("label") that test that criterion in this
            call, mark each "passed" true/false based on the transcript, with a one-sentence "note" justifying it.
            Copy each category's weight into its "weight" field. The overall "score" should reflect the weights.
            PROMPT;
    }

    /**
     * JSON schema for the report tool's "scorecard" property.
     *
     * @param  list<array{name: string, weight: int}>|null  $criteria
     * @return array<string, mixed>
     */
    public function reportSchema(?array $criteria): array
    {
        $names = $criteria === null ? self::FIXED_CATEGORIES : array_column($criteria, 'name');
        $count = count($names);

        return [
            'type' => 'array',
            'description' => $criteria === null
                ? 'Fixed 5-category rubric: Opener, Discovery, Social Proof, Takeaway, Closing — see the exact item wording in the prompt.'
                : 'The simulation\'s weighted scorecard — one category per criterion named in the prompt.',
            'minItems' => $count,
            'maxItems' => $count,
            'items' => [
                'type' => 'object',
                'required' => ['name', 'items'],
                'properties' => [
                    'name' => ['type' => 'string', 'enum' => array_values($names)],
                    'weight' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'The criterion weight (only for weighted scorecards)'],
                    'items' => [
                        'type' => 'array',
                        'minItems' => 1,
                        'maxItems' => $criteria === null ? 2 : 3,
                        'items' => [
                            'type' => 'object',
                            'required' => ['label', 'passed'],
                            'properties' => [
                                'label' => ['type' => 'string'],
                                'passed' => ['type' => 'boolean'],
                                'note' => ['type' => 'string', 'description' => 'One short sentence justifying the pass/fail'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Stamp each scorecard category with its weight and replace the overall
     * score with the weighted average of category pass rates. Without
     * criteria the report is returned unchanged.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array{name: string, weight: int}>|null  $criteria
     * @return array<string, mixed>
     */
    public function applyWeights(array $report, ?array $criteria): array
    {
        if ($criteria === null) {
            return $report;
        }

        $categories = collect($report['scorecard'] ?? [])->filter(fn ($c): bool => is_array($c));
        $byName = $categories->keyBy(fn (array $c): string => mb_strtolower(trim((string) ($c['name'] ?? ''))));

        $weighted = 0.0;
        $totalWeight = 0;
        $scorecard = [];

        foreach ($criteria as $criterion) {
            $category = $byName->get(mb_strtolower($criterion['name']), ['name' => $criterion['name'], 'items' => []]);
            $items = collect($category['items'] ?? []);
            $rate = $items->isEmpty() ? 0.0 : $items->filter(fn ($i): bool => (bool) ($i['passed'] ?? false))->count() / $items->count();

            $weighted += $rate * $criterion['weight'];
            $totalWeight += $criterion['weight'];
            $scorecard[] = [...$category, 'name' => $criterion['name'], 'weight' => $criterion['weight']];
        }

        $report['scorecard'] = $scorecard;
        if ($totalWeight > 0) {
            $report['score'] = (int) round($weighted / $totalWeight * 100);
        }

        return $report;
    }

    /**
     * Rescale weights to whole numbers that add up to exactly 100.
     *
     * @param  list<array{name: string, weight: int|float}>  $rows
     * @return list<array{name: string, weight: int}>
     */
    public function normalizeWeights(array $rows): array
    {
        $total = array_sum(array_map(fn (array $r): float => max(0, (float) $r['weight']), $rows));
        if ($rows === []) {
            return [];
        }

        $out = array_map(fn (array $r): array => [
            'name' => $r['name'],
            'weight' => max(1, (int) round($total > 0 ? max(0, (float) $r['weight']) / $total * 100 : 100 / count($rows))),
        ], $rows);

        // Push the rounding difference onto the heaviest criterion.
        $diff = 100 - array_sum(array_column($out, 'weight'));
        if ($diff !== 0) {
            $heaviest = array_keys(array_column($out, 'weight'), max(array_column($out, 'weight')))[0];
            $out[$heaviest]['weight'] = max(1, $out[$heaviest]['weight'] + $diff);
        }

        return array_values($out);
    }

    /**
     * Ask the AI for 3–6 weighted criteria for a simulation.
     *
     * @param  array<string, mixed>  $context  title, persona, scenario_brief, objections, win_criteria, loss_criteria
     * @return list<array{name: string, weight: int}>
     */
    public function draft(array $context): array
    {
        abort_if(blank(config('services.bedrock.model_id')), 500, 'BEDROCK_MODEL_ID is not configured on the server, so the AI scorecard draft is unavailable.');

        $objections = collect($context['objections'] ?? [])->filter()->map(fn ($o): string => '- '.$o)->implode("\n") ?: '(none)';
        $prompt = <<<PROMPT
            You are a real-estate sales coach designing the scorecard for a roleplay call that brokers will practise.
            Propose 3 to 6 scoring criteria for this simulation. Each criterion has a short name (2–5 words, e.g.
            "Discovery questions", "Handles price objection") and an integer weight; the weights must add up to 100
            and reflect what matters most to win this specific call.

            Simulation: {$this->field($context, 'title')}
            Client: {$this->field($context, 'persona')}
            Situation: {$this->field($context, 'scenario_brief')}
            Objections the client will raise:
            {$objections}
            The broker wins if: {$this->field($context, 'win_criteria')}
            The broker loses if: {$this->field($context, 'loss_criteria')}
            PROMPT;

        $client = new BedrockRuntimeClient([
            'region' => config('services.bedrock.region'),
            'version' => 'latest',
        ]);

        try {
            $result = $client->invokeModel([
                'modelId' => config('services.bedrock.model_id'),
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode([
                    'anthropic_version' => 'bedrock-2023-05-31',
                    'max_tokens' => 800,
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                    'tools' => [[
                        'name' => 'submit_scorecard',
                        'description' => 'Submit the weighted scorecard criteria.',
                        'input_schema' => [
                            'type' => 'object',
                            'required' => ['criteria'],
                            'properties' => [
                                'criteria' => [
                                    'type' => 'array',
                                    'minItems' => 3,
                                    'maxItems' => 6,
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['name', 'weight'],
                                        'properties' => [
                                            'name' => ['type' => 'string'],
                                            'weight' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]],
                    'tool_choice' => ['type' => 'tool', 'name' => 'submit_scorecard'],
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (AwsException $exception) {
            abort(502, 'AI scorecard draft failed: '.$exception->getAwsErrorMessage());
        }

        $body = json_decode($result['body']->getContents(), true);
        $toolUse = collect($body['content'] ?? [])->firstWhere('type', 'tool_use');
        $rows = $this->criteria(array_slice($toolUse['input']['criteria'] ?? [], 0, 6));

        abort_if($rows === null, 502, 'The AI did not return a scorecard.');

        return $this->normalizeWeights($rows);
    }

    /** @param  array<string, mixed>  $context */
    private function field(array $context, string $key): string
    {
        $value = trim((string) ($context[$key] ?? ''));

        return $value === '' ? '(not specified)' : $value;
    }
}
