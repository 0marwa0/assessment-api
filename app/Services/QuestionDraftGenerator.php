<?php

namespace App\Services;

use App\Models\Material;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Collection;

/**
 * R5: AI draft questions from Material Bank items. Nothing is saved; the
 * manager reviews each draft in the builder (keep / edit / discard).
 *
 * prompt() and parse() hold all the logic and are unit-tested; generate()
 * only adds the Bedrock call.
 */
class QuestionDraftGenerator
{
    public const TYPES = ['mcq', 'true_false', 'multi_select'];

    public const DIFFICULTIES = ['mixed', 'junior', 'senior'];

    /** Material text sent to the model, per request. */
    private const MAX_SOURCE_CHARS = 24000;

    /**
     * @param  Collection<int, Material>  $materials
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function generate(Collection $materials, int $count, array $types, string $difficulty): array
    {
        abort_if(blank(config('services.bedrock.model_id')), 500, 'BEDROCK_MODEL_ID is not configured on the server, so AI question drafts are unavailable.');

        $source = $this->sourceText($materials);
        abort_if($source === '', 422, 'The chosen material has no text to write questions from.');

        $client = new BedrockRuntimeClient(['region' => config('services.bedrock.region'), 'version' => 'latest']);

        try {
            $result = $client->invokeModel([
                'modelId' => config('services.bedrock.model_id'),
                'contentType' => 'application/json',
                'accept' => 'application/json',
                'body' => json_encode([
                    'anthropic_version' => 'bedrock-2023-05-31',
                    'max_tokens' => 4000,
                    'messages' => [['role' => 'user', 'content' => $this->prompt($source, $count, $types, $difficulty)]],
                    'tools' => [['name' => 'submit_questions', 'description' => 'Submit the draft questions.', 'input_schema' => $this->schema($count, $types)]],
                    'tool_choice' => ['type' => 'tool', 'name' => 'submit_questions'],
                ], JSON_THROW_ON_ERROR),
            ]);
        } catch (AwsException $exception) {
            abort(502, 'AI question drafts failed: '.$exception->getAwsErrorMessage());
        }

        $body = json_decode($result['body']->getContents(), true);
        $toolUse = collect($body['content'] ?? [])->firstWhere('type', 'tool_use');
        $drafts = $this->parse($toolUse['input']['questions'] ?? null, $source, $types, $count);

        abort_if($drafts === [], 502, 'The AI did not return any usable questions. Try again.');

        return $drafts;
    }

    /**
     * Plain text of the materials (HTML stripped), each under its title.
     *
     * @param  Collection<int, Material>  $materials
     */
    public function sourceText(Collection $materials): string
    {
        $text = $materials
            ->map(fn (Material $m): string => trim('# '.$m->title."\n".preg_replace('/\s+/u', ' ', $m->plainText())))
            ->implode("\n\n");

        return trim(mb_substr($text, 0, self::MAX_SOURCE_CHARS));
    }

    /**
     * @param  list<string>  $types
     */
    public function prompt(string $source, int $count, array $types, string $difficulty): string
    {
        $typeLines = collect($types)->map(fn (string $t): string => match ($t) {
            'true_false' => '- true_false: a statement; options are exactly "True" and "False", one correct',
            'multi_select' => '- multi_select: 4 options, 2 or 3 of them correct ("select all that apply")',
            default => '- mcq: single choice, 4 options, exactly one correct',
        })->implode("\n");
        $level = match ($difficulty) {
            'junior' => 'Write for junior brokers: recall of key facts and definitions.',
            'senior' => 'Write for senior brokers: applying the material to realistic client situations.',
            default => 'Mix easy recall questions with harder applied ones.',
        };

        return <<<PROMPT
            You write assessment questions for real-estate brokers in Dubai, using ONLY the training material below.
            Write {$count} questions. Spread them across these types:
            {$typeLines}
            {$level}

            Rules:
            - Every question must be answerable from the material alone. Do not use outside knowledge.
            - Wrong options must be plausible but clearly wrong according to the material.
            - "source_quote" must be one sentence copied word for word from the material that supports the answer.
            - "explanation" is one or two sentences shown to a broker who answered wrong.
            - "difficulty" is Easy, Medium or Hard.

            Material:
            <<<MATERIAL
            {$source}
            MATERIAL
            PROMPT;
    }

    /**
     * @param  list<string>  $types
     * @return array<string, mixed>
     */
    public function schema(int $count, array $types): array
    {
        return [
            'type' => 'object',
            'required' => ['questions'],
            'properties' => [
                'questions' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => $count,
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'question', 'options', 'source_quote'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => array_values($types)],
                            'question' => ['type' => 'string'],
                            'options' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['text', 'is_correct'],
                                    'properties' => ['text' => ['type' => 'string'], 'is_correct' => ['type' => 'boolean']],
                                ],
                            ],
                            'explanation' => ['type' => 'string'],
                            'difficulty' => ['type' => 'string', 'enum' => ['Easy', 'Medium', 'Hard']],
                            'source_quote' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Turns the model's output into draft questions in the builder's API shape,
     * dropping malformed ones. `source_found` is false when the quote can't be
     * found in the material (shown as "Check this").
     *
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function parse(mixed $raw, string $source, array $types, int $max): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [];
        }

        $haystack = $this->normalize($source);
        $drafts = [];

        foreach ($raw as $item) {
            if (! is_array($item) || count($drafts) >= $max) {
                continue;
            }
            $type = QuestionTypes::normalize(is_string($item['type'] ?? null) ? $item['type'] : '');
            $question = trim((string) ($item['question'] ?? ''));
            if (! in_array($type, $types, true) || $question === '') {
                continue;
            }

            $options = collect(is_array($item['options'] ?? null) ? $item['options'] : [])
                ->filter(fn ($o): bool => is_array($o) && trim((string) ($o['text'] ?? '')) !== '')
                ->map(fn (array $o): array => ['text' => trim((string) $o['text']), 'is_correct' => filter_var($o['is_correct'] ?? false, FILTER_VALIDATE_BOOL)])
                ->unique('text')->values()->take(6);

            if ($type === 'true_false') {
                $trueCorrect = (bool) $options->first(fn (array $o): bool => mb_strtolower($o['text']) === 'true')['is_correct'];
                $falseCorrect = (bool) $options->first(fn (array $o): bool => mb_strtolower($o['text']) === 'false')['is_correct'];
                if ($trueCorrect === $falseCorrect) {
                    continue;
                }
                $options = collect([['text' => 'True', 'is_correct' => $trueCorrect], ['text' => 'False', 'is_correct' => $falseCorrect]]);
            }

            $correct = $options->where('is_correct', true)->count();
            $valid = match ($type) {
                'mcq' => $options->count() >= 2 && $correct === 1,
                'multi_select' => $options->count() >= 3 && $correct >= 1,
                default => true,
            };
            if (! $valid) {
                continue;
            }

            $quote = trim((string) ($item['source_quote'] ?? ''), " \t\n\r\"'“”");
            $difficulty = in_array($item['difficulty'] ?? null, ['Easy', 'Medium', 'Hard'], true) ? $item['difficulty'] : 'Medium';

            $drafts[] = [
                'type' => $type,
                'question_text' => mb_substr($question, 0, 5000),
                'options' => $options->values()->map(fn (array $o, int $i): array => ['label' => chr(65 + $i), ...$o])->all(),
                'answer_explanation' => mb_substr(trim((string) ($item['explanation'] ?? '')), 0, 5000),
                'difficulty_level' => $difficulty,
                'source_quote' => $quote,
                'source_found' => mb_strlen($quote) >= 12 && str_contains($haystack, $this->normalize($quote)),
            ];
        }

        return $drafts;
    }

    private function normalize(string $text): string
    {
        $text = str_replace(['“', '”', '‘', '’'], ['"', '"', "'", "'"], mb_strtolower($text));

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text));
    }
}
