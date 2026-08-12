<?php

/**
 * Unit tests for ReadableTextExtractor (web-research-tool).
 *
 * Covers script/style/nav/footer stripping, whitespace collapsing, empty/malformed
 * HTML handling (never throws), and plain-text passthrough is NOT this class's
 * concern (WebFetchService only calls it for `text/html`).
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\WebResearch
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\WebResearch;

use OCA\Hermiq\Service\WebResearch\ReadableTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ReadableTextExtractor.
 *
 * @spec openspec/changes/web-research-tool/tasks.md#task-5-webfetchservice--readabletextextractor
 */
class ReadableTextExtractorTest extends TestCase {

	/**
	 * Script/style/nav/footer markup is stripped and only readable body text remains.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/web-research-tool/specs/web-research-tool/spec.md#scenario-an-agent-fetches-an-html-page
	 */
	public function testStripsScriptStyleNavAndFooter(): void {
		$html = <<<HTML
            <html><head><style>.x{color:red}</style></head>
            <body>
                <nav>Home | About</nav>
                <script>alert('hi')</script>
                <main><p>The quick brown fox.</p></main>
                <footer>Copyright 2026</footer>
            </body></html>
            HTML;

		$extractor = new ReadableTextExtractor();
		$text = $extractor->extract(html: $html);

		$this->assertStringContainsString('The quick brown fox.', $text);
		$this->assertStringNotContainsString('alert', $text);
		$this->assertStringNotContainsString('color:red', $text);
		$this->assertStringNotContainsString('Home | About', $text);
		$this->assertStringNotContainsString('Copyright 2026', $text);

	}//end testStripsScriptStyleNavAndFooter()

	/**
	 * Whitespace (newlines, tabs, repeated spaces) collapses to single spaces.
	 *
	 * @return void
	 */
	public function testCollapsesWhitespace(): void {
		$html = "<html><body>\n\n<p>Hello   \t\n  world</p>\n</body></html>";

		$extractor = new ReadableTextExtractor();
		$text = $extractor->extract(html: $html);

		$this->assertSame('Hello world', $text);

	}//end testCollapsesWhitespace()

	/**
	 * Empty input returns an empty string, never an exception.
	 *
	 * @return void
	 */
	public function testEmptyInputReturnsEmptyString(): void {
		$extractor = new ReadableTextExtractor();

		$this->assertSame('', $extractor->extract(html: ''));
		$this->assertSame('', $extractor->extract(html: '   '));

	}//end testEmptyInputReturnsEmptyString()

	/**
	 * Malformed / garbage HTML never throws — it yields the best-effort text (or
	 * empty), never an exception (this app's "never throw" ethos one layer down).
	 *
	 * @return void
	 */
	public function testMalformedHtmlNeverThrows(): void {
		$extractor = new ReadableTextExtractor();

		$text = $extractor->extract(html: '<html><body><p>Unclosed <div>tags<span>everywhere');

		$this->assertStringContainsString('Unclosed', $text);
		$this->assertStringContainsString('tags', $text);

	}//end testMalformedHtmlNeverThrows()

	/**
	 * A document with no `<body>` at all still yields whatever text is present,
	 * rather than throwing or erroring.
	 *
	 * @return void
	 */
	public function testNoBodyTagStillExtractsText(): void {
		$extractor = new ReadableTextExtractor();

		$text = $extractor->extract(html: 'Just some bare text, no html wrapper at all.');

		$this->assertStringContainsString('Just some bare text', $text);

	}//end testNoBodyTagStillExtractsText()
}//end class
