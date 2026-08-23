<?php

declare(strict_types=1);

namespace App\Support;

use DOMDocument;
use DOMElement;

/**
 * Whitelist sanitizer for uploaded SVG branding.
 *
 * Branding files are served from the public disk on the application's own origin,
 * so an SVG opened as a top-level document runs whatever script it carries — i.e.
 * stored XSS. Logos are vector by nature, so instead of banning the format the
 * upload is rewritten: elements outside the whitelist are dropped, event handlers
 * and every reference that leaves the document go with them, and only the result
 * is written to disk. The original bytes are never stored.
 */
final class SvgSanitizer
{
    private const SVG_NS = 'http://www.w3.org/2000/svg';

    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    private const XMLNS_NS = 'http://www.w3.org/2000/xmlns/';

    /** Elements a logo or icon legitimately needs; everything else is removed. */
    private const ELEMENTS = [
        'svg', 'g', 'defs', 'symbol', 'use', 'switch', 'title', 'desc', 'metadata', 'style',
        'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon',
        'text', 'tspan', 'textpath', 'image',
        'clippath', 'mask', 'pattern', 'marker',
        'lineargradient', 'radialgradient', 'stop',
        'filter', 'fegaussianblur', 'feoffset', 'feblend', 'fecolormatrix', 'feflood',
        'fecomposite', 'femerge', 'femergenode', 'fedropshadow', 'femorphology', 'fetile',
    ];

    /** CSS that reaches outside the document or is a known script vector. */
    private const CSS_DANGER = '/@import|expression\s*\(|javascript\s*:|-moz-binding|behavi(o|&#x6F;)r\s*:/i';

    /**
     * Return the sanitized SVG markup, or null when the input is not an SVG
     * document at all (wrong root element, malformed XML, empty).
     */
    public static function sanitize(string $svg): ?string
    {
        if (trim($svg) === '') {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        // No LIBXML_NOENT: entity references are left unexpanded (no billion laughs),
        // and LIBXML_NONET blocks any network fetch while parsing.
        $loaded = $doc->loadXML($svg, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            return null;
        }

        // Drop the DTD (an internal subset can declare entities) and any top-level
        // processing instruction / comment.
        foreach (iterator_to_array($doc->childNodes) as $node) {
            if ($node !== $root) {
                $doc->removeChild($node);
            }
        }

        self::clean($root);

        $out = $doc->saveXML($root);

        return is_string($out) && $out !== '' ? $out : null;
    }

    /** Strip disallowed children recursively, then the element's own attributes. */
    private static function clean(DOMElement $element): void
    {
        foreach (iterator_to_array($element->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (self::allowed($child)) {
                    self::clean($child);
                } else {
                    $element->removeChild($child);
                }

                continue;
            }

            // Text and CDATA stay; comments, PIs and entity references do not.
            if ($child->nodeType !== XML_TEXT_NODE && $child->nodeType !== XML_CDATA_SECTION_NODE) {
                $element->removeChild($child);
            }
        }

        self::cleanAttributes($element);
    }

    private static function allowed(DOMElement $element): bool
    {
        // Foreign namespaces (Illustrator's i:/x:, and anything smuggled in) are out.
        if ($element->namespaceURI !== null && $element->namespaceURI !== self::SVG_NS) {
            return false;
        }

        if (! in_array(strtolower($element->localName), self::ELEMENTS, true)) {
            return false;
        }

        // <style> is kept because Illustrator exports put the fills there, but only
        // when the CSS neither imports nor points outside the document.
        if (strtolower($element->localName) === 'style') {
            $css = $element->textContent;

            return preg_match(self::CSS_DANGER, $css) !== 1 && ! self::hasExternalRef($css);
        }

        return true;
    }

    private static function cleanAttributes(DOMElement $element): void
    {
        $isImage = strtolower($element->localName) === 'image';

        foreach (iterator_to_array($element->attributes) as $attribute) {
            // Namespace declarations are inert and removing them breaks the document.
            if ($attribute->namespaceURI === self::XMLNS_NS) {
                continue;
            }

            $name = strtolower($attribute->localName);
            $value = $attribute->value;

            $drop = match (true) {
                // Attributes from foreign namespaces (xlink and xml:* excepted).
                $attribute->namespaceURI !== null
                    && $attribute->namespaceURI !== self::SVG_NS
                    && $attribute->namespaceURI !== self::XLINK_NS
                    && $attribute->namespaceURI !== self::XML_NS => true,
                // Event handlers, the one place an SVG attribute can hold script.
                str_starts_with($name, 'on') => true,
                // Links: same-document fragments only (plus embedded raster data on <image>).
                $name === 'href' => ! self::safeHref($value, $isImage),
                $name === 'style' => preg_match(self::CSS_DANGER, $value) === 1 || self::hasExternalRef($value),
                // filter="url(https://evil/#x)" and friends.
                default => self::hasExternalRef($value) || self::hasScriptUrl($value),
            };

            if ($drop) {
                $element->removeAttributeNode($attribute);
            }
        }
    }

    private static function safeHref(string $value, bool $isImage): bool
    {
        $value = self::normalize($value);

        return str_starts_with($value, '#') || ($isImage && str_starts_with($value, 'data:image/'));
    }

    /** True when a value references a resource that is not a document fragment. */
    private static function hasExternalRef(string $value): bool
    {
        return preg_match('/url\(\s*[\'"]?\s*(?!#)/i', self::normalize($value)) === 1;
    }

    private static function hasScriptUrl(string $value): bool
    {
        return str_contains(self::normalize($value), 'javascript:');
    }

    /** Lowercase and drop whitespace/control chars, the usual `java\nscript:` dodge. */
    private static function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[\s\x00-\x1F]+/', '', $value));
    }
}
