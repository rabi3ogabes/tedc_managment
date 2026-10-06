<?php

namespace App\Services\Cms;

use DOMDocument;
use DOMElement;
use DOMNode;

/** Keeps the formatting an editor needs (paragraphs, headings, lists, links, images) and removes everything that could run code. */
class HtmlSanitizer
{
    private const TAGS = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ul', 'ol', 'li', 'a', 'h2', 'h3', 'h4', 'blockquote', 'img', 'span', 'div'];

    private const ATTRS = ['a' => ['href', 'title', 'target', 'rel'], 'img' => ['src', 'alt', 'title'], '*' => ['dir', 'class']];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);
        if ($html === '') {
            return '';
        }
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('root');
        if (! $root) {
            return e(strip_tags($html));
        }
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    private static function walk(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $node->removeChild($child);
                }

                continue;
            }
            $tag = strtolower($child->tagName);
            if (! in_array($tag, self::TAGS, true)) {
                // Scripts and styles vanish with their content; other unknown tags keep their text.
                if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'form', 'svg', 'math', 'link', 'meta'], true)) {
                    $node->removeChild($child);
                } else {
                    self::walk($child);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                }

                continue;
            }
            foreach (iterator_to_array($child->attributes) as $attr) {
                $name = strtolower($attr->name);
                $ok = in_array($name, self::ATTRS[$tag] ?? [], true) || in_array($name, self::ATTRS['*'], true);
                if ($ok && in_array($name, ['href', 'src'], true) && ! self::safeUrl($attr->value, $name === 'src')) {
                    $ok = false;
                }
                if (! $ok) {
                    $child->removeAttribute($attr->name);
                }
            }
            if ($tag === 'a' && $child->hasAttribute('href')) {
                $child->setAttribute('rel', 'noopener noreferrer');
            }
            self::walk($child);
        }
    }

    private static function safeUrl(string $url, bool $image): bool
    {
        $url = trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');

        return (bool) preg_match($image ? '#^(https?://|/)#i' : '#^(https?://|mailto:|tel:|/|\#)#i', $url);
    }
}
