<?php

/**
 * Hermiq PassageText: passages of bounded size and how well they match a question.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Chat
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/chat-about-a-document-cites-its-sources/tasks.md#task-2-retrieval-honours-a-file-or-folder-scope
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Chat;

use OCA\Hermiq\Service\Chat\PassageText;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the passage splitter and scorer.
 */
class PassageTextTest extends TestCase {

	/**
	 * Short paragraphs share a passage; blank paragraphs are dropped.
	 *
	 * @return void
	 */
	public function testShortParagraphsShareAPassage(): void {
		$this->assertSame(["Eerste alinea.\n\nTweede alinea."], (new PassageText())->chunks(text: "Eerste alinea.\n\n  \n\nTweede alinea.\n"));
		$this->assertSame([], (new PassageText())->chunks(text: "\n\n"));
	}

	/**
	 * A passage never exceeds the size; a long paragraph is cut, and the next
	 * paragraph starts a new passage when it would not fit.
	 *
	 * @return void
	 */
	public function testPassagesStayWithinTheirSize(): void {
		$long = str_repeat('a', PassageText::PASSAGE_CHARS + 100);
		$chunks = (new PassageText())->chunks(text: $long . "\n\n" . str_repeat('b', PassageText::PASSAGE_CHARS - 10));

		$this->assertSame([PassageText::PASSAGE_CHARS, 100, PassageText::PASSAGE_CHARS - 10], array_map('mb_strlen', $chunks));
		$this->assertSame(str_repeat('b', PassageText::PASSAGE_CHARS - 10), $chunks[2]);
	}

	/**
	 * The terms are the distinct lower-cased words of at least four characters.
	 *
	 * @return void
	 */
	public function testTermsAreTheLongerDistinctWords(): void {
		$this->assertSame(['beslistermijn', 'bezwaar'], (new PassageText())->terms(text: 'Wat is de Beslistermijn? En de beslistermijn bij bezwaar?'));
		$this->assertSame([], (new PassageText())->terms(text: 'Wat is de?'));
	}

	/**
	 * The score is the share of terms the passage contains, ignoring case.
	 *
	 * @return void
	 */
	public function testTheScoreIsTheShareOfTermsFound(): void {
		$text = new PassageText();

		$this->assertSame(1.0, $text->score(text: 'De BESLISTERMIJN bij bezwaar', terms: ['beslistermijn', 'bezwaar']));
		$this->assertSame(0.5, $text->score(text: 'De beslistermijn', terms: ['beslistermijn', 'bezwaar']));
		$this->assertSame(0.0, $text->score(text: 'Niets', terms: ['beslistermijn']));
	}
}
