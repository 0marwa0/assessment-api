<?php

namespace App\Services;

/**
 * MG10: turns an old step-by-step material (a list of `stages`) into the new
 * single rich-text document, so nothing written before is lost.
 *
 * - text stage   -> <h2>stage title</h2> + its body as paragraphs
 *                   (blank line = new <p>, single line break = <br>; text escaped)
 * - pdf/video/link stage with a url -> an attachment {name, url, type}
 *   (its title becomes the name; any body text it had is kept as text)
 * - stage without a url and without text -> its title as a heading
 *
 * The `stages` column itself is left untouched, for rollback.
 */
class MaterialContentConverter
{
    /**
     * @param  array<int, array<string, mixed>>|null  $stages
     * @return array{content: string, attachments: array<int, array{name: string, url: string, type: string}>}
     */
    public function fromStages(?array $stages): array
    {
        $html = [];
        $attachments = [];

        foreach (array_values($stages ?? []) as $index => $stage) {
            if (! is_array($stage)) {
                continue;
            }
            $type = in_array($stage['type'] ?? 'text', ['video', 'pdf', 'link'], true) ? $stage['type'] : 'text';
            $title = trim((string) ($stage['title'] ?? ''));
            $body = trim((string) ($stage['body'] ?? ''));
            $url = trim((string) ($stage['url'] ?? ''));

            if ($type !== 'text' && $url !== '') {
                $attachments[] = [
                    'name' => $title !== '' ? $title : $this->nameFromUrl($url, $index),
                    'url' => $url,
                    'type' => $type,
                ];
                if ($body !== '') {
                    $html[] = $this->paragraphs($body);
                }

                continue;
            }

            if ($title !== '') {
                $html[] = '<h2>'.$this->escape($title).'</h2>';
            }
            if ($body !== '') {
                $html[] = $this->paragraphs($body);
            }
        }

        return [
            'content' => implode("\n", array_filter($html)),
            'attachments' => $attachments,
        ];
    }

    private function paragraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = preg_split('/\n\s*\n/', $text) ?: [];

        $out = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }
            $lines = array_map(fn (string $line): string => $this->escape(trim($line)), explode("\n", $block));
            $out[] = '<p>'.implode('<br>', $lines).'</p>';
        }

        return implode("\n", $out);
    }

    private function nameFromUrl(string $url, int $index): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $path !== '' ? rawurldecode(basename($path)) : '';

        return $base !== '' ? $base : 'Attachment '.($index + 1);
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
