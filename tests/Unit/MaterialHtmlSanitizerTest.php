<?php

namespace Tests\Unit;

use App\Services\MaterialHtmlSanitizer;
use PHPUnit\Framework\TestCase;

class MaterialHtmlSanitizerTest extends TestCase
{
    private function clean(string $html): string
    {
        return (new MaterialHtmlSanitizer)->sanitize($html);
    }

    public function test_it_keeps_the_allowed_tags(): void
    {
        $html = '<h2>Title</h2><h3>Sub</h3><p>A <strong>b</strong> <b>c</b> <em>d</em> <i>e</i><br>f</p>'
            .'<ul><li>one</li></ul><ol><li>two</li></ol><blockquote>quote</blockquote>'
            .'<div data-keypoint="">Key</div>';

        $out = $this->clean($html);

        foreach (['<h2>Title</h2>', '<h3>Sub</h3>', '<strong>b</strong>', '<b>c</b>', '<em>d</em>', '<i>e</i>', '<br>', '<ul><li>one</li></ul>', '<ol><li>two</li></ol>', '<blockquote>quote</blockquote>', '<div data-keypoint="">Key</div>'] as $fragment) {
            $this->assertStringContainsString($fragment, $out);
        }
    }

    public function test_it_removes_scripts_styles_and_event_handlers(): void
    {
        $out = $this->clean('<p onclick="steal()" style="color:red" class="x">Hi</p><script>alert(1)</script><style>p{}</style><img src="x" onerror="alert(1)"><iframe src="https://evil.test"></iframe>');

        $this->assertSame('<p>Hi</p>', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    public function test_it_only_keeps_http_https_and_mailto_links(): void
    {
        $out = $this->clean('<p><a href="javascript:alert(1)">bad</a> <a href=" JaVa&#x09;script:alert(1)">bad2</a> <a href="data:text/html,x">bad3</a> <a href="https://ok.test/a" onmouseover="x()">ok</a> <a href="mailto:a@b.test">mail</a></p>');

        $this->assertStringNotContainsString('javascript', strtolower($out));
        $this->assertStringNotContainsString('data:', $out);
        $this->assertStringContainsString('bad bad2 bad3', $out); // link text is kept
        $this->assertStringContainsString('<a href="https://ok.test/a" rel="noopener noreferrer" target="_blank">ok</a>', $out);
        $this->assertStringContainsString('<a href="mailto:a@b.test" rel="noopener noreferrer" target="_blank">mail</a>', $out);
        $this->assertStringNotContainsString('onmouseover', $out);
    }

    public function test_it_only_keeps_images_from_http_or_uploads(): void
    {
        $out = $this->clean('<p><img src="https://cdn.test/a.png" alt="A" width="5"><img src="/storage/materials/images/b.png"><img src="javascript:alert(1)"><img src="data:image/png;base64,AAA"></p>');

        $this->assertStringContainsString('<img src="https://cdn.test/a.png" alt="A">', $out);
        $this->assertStringContainsString('<img src="/storage/materials/images/b.png" alt="">', $out);
        $this->assertStringNotContainsString('javascript', $out);
        $this->assertStringNotContainsString('data:', $out);
    }

    public function test_it_unwraps_unknown_tags_and_maps_headings(): void
    {
        $out = $this->clean('<h1>Big</h1><h4>Small</h4><span style="x">kept <u>text</u></span><div>line</div><div class="keypoint">no attr</div>');

        $this->assertStringContainsString('<h2>Big</h2>', $out);
        $this->assertStringContainsString('<h3>Small</h3>', $out);
        $this->assertStringContainsString('kept text', $out);
        $this->assertStringContainsString('<p>line</p>', $out);
        $this->assertStringContainsString('<p>no attr</p>', $out);
        $this->assertStringNotContainsString('<span', $out);
    }

    public function test_empty_input_gives_empty_string(): void
    {
        $this->assertSame('', $this->clean(''));
        $this->assertSame('', $this->clean('<script>x</script>'));
    }
}
