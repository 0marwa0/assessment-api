<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans the rich-text body of a material (MG10) before it is stored, so the
 * broker reading view can render it as HTML safely.
 *
 * Allowlist only. Kept: h2, h3, p, br, strong, b, em, i, ul, ol, li,
 * blockquote, a[href], img[src, alt] and div[data-keypoint] (the "Key point" box).
 * - Links: http, https and mailto only; always get rel="noopener noreferrer" target="_blank".
 * - Images: http/https or the app's own upload paths (/storage/...) only.
 * - h1 becomes h2 and h4-h6 become h3 (pasted documents); a plain div becomes a p.
 * - script/style/iframe/etc. are dropped with their content; every other tag
 *   is unwrapped (its text is kept); every other attribute (on*, style, class,
 *   id, ...) is removed.
 */
class MaterialHtmlSanitizer
{
    private const ALLOWED = ['h2', 'h3', 'p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a', 'img', 'blockquote', 'div'];

    private const RENAME = ['h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3'];

    /** Removed together with everything inside them. */
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'template', 'noscript', 'svg', 'math', 'head', 'title', 'meta', 'link', 'base', 'form', 'input', 'button', 'select', 'textarea', 'frame', 'frameset', 'applet', 'audio', 'video', 'canvas'];

    private const BLOCK = ['h2', 'h3', 'p', 'ul', 'ol', 'li', 'blockquote', 'div', 'h1', 'h4', 'h5', 'h6', 'table'];

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="__mg10_root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('__mg10_root');
        if (! $root) {
            return '';
        }

        $this->cleanChildren($root, $doc);

        $out = '';
        foreach (iterator_to_array($root->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    public static function isSafeLink(string $url): bool
    {
        $url = self::normalizeUrl($url);

        return (bool) preg_match('#^(https?://[^\s]+|mailto:[^\s]+)$#i', $url);
    }

    public static function isSafeImage(string $url): bool
    {
        $url = self::normalizeUrl($url);

        return (bool) preg_match('#^(https?://[^\s]+|/storage/[^\s]+)$#i', $url);
    }

    private static function normalizeUrl(string $url): string
    {
        // Browsers ignore control characters and whitespace inside a scheme
        // ("java\tscript:"), so strip them before checking.
        return (string) preg_replace('/[\x00-\x20\x7F]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanChildren(DOMNode $parent, DOMDocument $doc): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            $this->cleanNode($node, $doc);
        }
    }

    private function cleanNode(DOMNode $node, DOMDocument $doc): void
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            return;
        }

        if (! $node instanceof DOMElement) {
            // Comments, processing instructions, CDATA, ...
            $node->parentNode?->removeChild($node);

            return;
        }

        $tag = strtolower($node->nodeName);

        if (in_array($tag, self::DROP, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }

        if (isset(self::RENAME[$tag])) {
            $node = $this->rename($node, self::RENAME[$tag], $doc);
            $tag = self::RENAME[$tag];
        }

        if (! in_array($tag, self::ALLOWED, true)) {
            $this->unwrap($node, $doc);

            return;
        }

        // Clean children first (an element may be replaced below).
        $this->cleanChildren($node, $doc);

        $keypoint = $tag === 'div' && $node->hasAttribute('data-keypoint');
        $href = $tag === 'a' ? $node->getAttribute('href') : '';
        $src = $tag === 'img' ? $node->getAttribute('src') : '';
        $alt = $tag === 'img' ? $node->getAttribute('alt') : '';

        foreach (iterator_to_array($node->attributes) as $attribute) {
            $node->removeAttribute($attribute->nodeName);
        }

        switch ($tag) {
            case 'a':
                if (! self::isSafeLink($href)) {
                    $this->unwrap($node, $doc);

                    return;
                }
                $node->setAttribute('href', trim($href));
                $node->setAttribute('rel', 'noopener noreferrer');
                $node->setAttribute('target', '_blank');
                break;
            case 'img':
                if (! self::isSafeImage($src)) {
                    $node->parentNode?->removeChild($node);

                    return;
                }
                $node->setAttribute('src', trim($src));
                $node->setAttribute('alt', mb_substr($alt, 0, 300));
                break;
            case 'div':
                if ($keypoint) {
                    $node->setAttribute('data-keypoint', '');
                } elseif ($this->hasBlockChild($node)) {
                    $this->unwrap($node, $doc);
                } else {
                    $this->rename($node, 'p', $doc);
                }
                break;
        }
    }

    private function hasBlockChild(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->nodeName), self::BLOCK, true)) {
                return true;
            }
        }

        return false;
    }

    private function unwrap(DOMElement $node, DOMDocument $doc): void
    {
        $parent = $node->parentNode;
        if (! $parent) {
            return;
        }
        $children = iterator_to_array($node->childNodes);
        foreach ($children as $child) {
            $parent->insertBefore($child, $node);
        }
        $parent->removeChild($node);
        foreach ($children as $child) {
            if ($child->parentNode === $parent) {
                $this->cleanNode($child, $doc);
            }
        }
    }

    private function rename(DOMElement $node, string $tag, DOMDocument $doc): DOMElement
    {
        $replacement = $doc->createElement($tag);
        foreach (iterator_to_array($node->attributes) as $attribute) {
            $replacement->setAttribute($attribute->nodeName, $attribute->nodeValue ?? '');
        }
        while ($node->firstChild) {
            $replacement->appendChild($node->firstChild);
        }
        $node->parentNode?->replaceChild($replacement, $node);

        return $replacement;
    }
}
