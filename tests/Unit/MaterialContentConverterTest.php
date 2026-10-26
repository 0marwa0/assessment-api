<?php

namespace Tests\Unit;

use App\Services\MaterialContentConverter;
use PHPUnit\Framework\TestCase;

class MaterialContentConverterTest extends TestCase
{
    public function test_text_stages_become_headings_and_paragraphs(): void
    {
        $result = (new MaterialContentConverter)->fromStages([
            ['id' => 's1', 'title' => 'What <makes> a contract', 'status' => 'not-complete', 'type' => 'text', 'body' => "Line one\nLine two\n\nSecond para & more"],
            ['id' => 's2', 'title' => 'Only a title', 'status' => 'complete', 'type' => 'text', 'body' => ''],
        ]);

        $this->assertSame(
            "<h2>What &lt;makes&gt; a contract</h2>\n<p>Line one<br>Line two</p>\n<p>Second para &amp; more</p>\n<h2>Only a title</h2>",
            $result['content']
        );
        $this->assertSame([], $result['attachments']);
    }

    public function test_file_and_link_stages_become_attachments(): void
    {
        $result = (new MaterialContentConverter)->fromStages([
            ['id' => 's1', 'title' => 'RERA guide', 'type' => 'pdf', 'body' => '', 'url' => 'https://x.test/storage/materials/pdfs/guide.pdf'],
            ['id' => 's2', 'title' => '', 'type' => 'video', 'body' => 'Watch this first', 'url' => 'https://x.test/storage/materials/videos/intro.mp4'],
            ['id' => 's3', 'title' => 'DLD site', 'type' => 'link', 'url' => 'https://dubailand.gov.ae'],
        ]);

        $this->assertSame([
            ['name' => 'RERA guide', 'url' => 'https://x.test/storage/materials/pdfs/guide.pdf', 'type' => 'pdf'],
            ['name' => 'intro.mp4', 'url' => 'https://x.test/storage/materials/videos/intro.mp4', 'type' => 'video'],
            ['name' => 'DLD site', 'url' => 'https://dubailand.gov.ae', 'type' => 'link'],
        ], $result['attachments']);
        $this->assertSame('<p>Watch this first</p>', $result['content']);
    }

    public function test_empty_or_missing_stages_give_empty_result(): void
    {
        $this->assertSame(['content' => '', 'attachments' => []], (new MaterialContentConverter)->fromStages(null));
        $this->assertSame(['content' => '', 'attachments' => []], (new MaterialContentConverter)->fromStages([]));
    }
}
