<?php

/**
 * Hermiq ReadableTextExtractor.
 *
 * Extracts readable text from an HTML document via PHP's native
 * `DOMDocument`/`DOMXPath` (no new third-party readability library, per proposal.md
 * "In Scope"). Strips script/style/navigation markup and collapses whitespace. A pure
 * function — no DI, no I/O.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#requirement-webfetch-extracts-readable-text-with-a-content-type-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\WebResearch;

use DOMDocument;
use DOMXPath;

/**
 * HTML → readable-text extraction.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-5-webfetchservice--readabletextextractor
 */
class ReadableTextExtractor
{

    /**
     * Element names stripped entirely before text extraction.
     *
     * @var string[]
     */
    private const STRIPPED_TAGS = ['script', 'style', 'nav', 'footer', 'noscript', 'header', 'aside'];

    /**
     * Extract readable text from an HTML string.
     *
     * Malformed markup never throws: `libxml_use_internal_errors()` suppresses parse
     * warnings and a document that fails to load at all yields an empty string rather
     * than an exception (mirrors this app's "never throw" tool-result ethos one layer
     * down).
     *
     * @param string $html The raw HTML response body.
     *
     * @return string The extracted, whitespace-collapsed readable text.
     *
     * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
     */
    public function extract(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $previousSetting = libxml_use_internal_errors(true);

        $document = new DOMDocument();
        // The leading pseudo-declaration forces UTF-8 interpretation regardless of a
        // page's own (possibly absent/wrong) declared charset — a well-known
        // DOMDocument idiom, since loadHTML() otherwise assumes ISO-8859-1.
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?>'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previousSetting);

        if ($loaded === false) {
            return '';
        }

        $xpath = new DOMXPath($document);
        foreach (self::STRIPPED_TAGS as $tag) {
            $nodes = $xpath->query('//'.$tag);
            if ($nodes === false) {
                continue;
            }

            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $body = $document->getElementsByTagName('body')->item(0);
        $text = $document->textContent;
        if ($body !== null) {
            $text = $body->textContent;
        }

        $collapsed = preg_replace('/\s+/u', ' ', (string) $text);

        return trim((string) $collapsed);

    }//end extract()
}//end class
