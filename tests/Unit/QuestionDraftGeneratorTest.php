<?php

namespace Tests\Unit;

use App\Services\QuestionDraftGenerator;
use PHPUnit\Framework\TestCase;

class QuestionDraftGeneratorTest extends TestCase
{
    private const SOURCE = "# Escrow\nDevelopers must keep a separate escrow account for each off-plan project. Funds may only be used for construction.";

    public function test_parse_keeps_valid_drafts_and_flags_missing_sources(): void
    {
        $drafts = (new QuestionDraftGenerator)->parse([
            ['type' => 'mcq', 'question' => 'Where do buyer payments go?', 'options' => [
                ['text' => 'A project escrow account', 'is_correct' => true], ['text' => 'The broker', 'is_correct' => false],
            ], 'source_quote' => '“Developers must keep a separate escrow account for each off-plan project.”', 'difficulty' => 'Easy'],
            ['type' => 'true_false', 'question' => 'Escrow funds can pay for marketing.', 'options' => [
                ['text' => 'false', 'is_correct' => true], ['text' => 'true', 'is_correct' => false],
            ], 'source_quote' => 'Something the material never says at all.'],
        ], self::SOURCE, ['mcq', 'true_false'], 10);

        $this->assertCount(2, $drafts);
        $this->assertSame('mcq', $drafts[0]['type']);
        $this->assertSame(['A', 'B'], array_column($drafts[0]['options'], 'label'));
        $this->assertTrue($drafts[0]['source_found']);
        $this->assertSame('Easy', $drafts[0]['difficulty_level']);
        // True/false normalised to True then False, one correct; unknown quote → "Check this".
        $this->assertSame([['label' => 'A', 'text' => 'True', 'is_correct' => false], ['label' => 'B', 'text' => 'False', 'is_correct' => true]], $drafts[1]['options']);
        $this->assertFalse($drafts[1]['source_found']);
        $this->assertSame('Medium', $drafts[1]['difficulty_level']);
    }

    public function test_parse_drops_malformed_and_unrequested_drafts_and_caps_the_count(): void
    {
        $generator = new QuestionDraftGenerator;
        $mcq = fn (string $q) => ['type' => 'mcq', 'question' => $q, 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => false]], 'source_quote' => ''];

        $drafts = $generator->parse([
            ['type' => 'mcq', 'question' => 'Two right?', 'options' => [['text' => 'a', 'is_correct' => true], ['text' => 'b', 'is_correct' => true]]],
            ['type' => 'true_false', 'question' => 'Both true?', 'options' => [['text' => 'True', 'is_correct' => true], ['text' => 'False', 'is_correct' => true]]],
            ['type' => 'multi_select', 'question' => 'Not requested', 'options' => []],
            ['type' => 'mcq', 'question' => '', 'options' => []],
            'garbage',
            $mcq('Q1'), $mcq('Q2'), $mcq('Q3'),
        ], self::SOURCE, ['mcq', 'true_false'], 2);

        $this->assertSame(['Q1', 'Q2'], array_column($drafts, 'question_text'));
        $this->assertSame([], $generator->parse('not json', self::SOURCE, ['mcq'], 5));
        $this->assertCount(1, $generator->parse(json_encode([$mcq('From JSON')]), self::SOURCE, ['mcq'], 5));
    }

    public function test_prompt_includes_material_types_and_level(): void
    {
        $prompt = (new QuestionDraftGenerator)->prompt(self::SOURCE, 4, ['true_false'], 'senior');

        $this->assertStringContainsString('Write 4 questions', $prompt);
        $this->assertStringContainsString('true_false', $prompt);
        $this->assertStringNotContainsString('- mcq', $prompt);
        $this->assertStringContainsString('senior brokers', $prompt);
        $this->assertStringContainsString('separate escrow account', $prompt);
    }
}
