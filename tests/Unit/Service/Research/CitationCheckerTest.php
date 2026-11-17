<?php

/**
 * Unit tests for CitationChecker (tools-deep-research-report).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Research
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-every-citation-is-checked-against-what-was-read-req-research-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use DateTimeImmutable;
use OCA\Hermiq\Service\Research\CitationChecker;
use OCA\Hermiq\Service\Research\SourceLedger;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\CitationChecker
 * @covers \OCA\Hermiq\Service\Research\CitationCheck
 * @uses \OCA\Hermiq\Service\Research\SourceLedger
 */
final class CitationCheckerTest extends TestCase {

	private static function ledger(): SourceLedger {
		$ledger = new SourceLedger();
		$at     = new DateTimeImmutable('2026-11-13T10:00:00+00:00');
		$ledger->record(['url' => 'https://a.example/', 'content' => "Utrecht publiceert sinds 2022 zijn  algoritmen\nin een openbaar register."], 'A', $at);
		$ledger->record(['url' => 'https://b.example/', 'content' => 'Amsterdam noemt het "Algoritmeregister" een publiek overzicht.'], 'B', $at);

		return $ledger;
	}

	public function testAMatchingCitationPassesAndAWrongNumberAndAnInventedPassageAreRemoved(): void {
		$draft = 'Utrecht publiceert sinds 2022 [1: "publiceert sinds 2022 zijn algoritmen"]. '
			. 'Rotterdam doet dat ook [2: "Rotterdam publiceert alles"]. '
			. 'Den Haag volgt [7: "publiceert sinds 2022"].';

		$check = (new CitationChecker())->check($draft, self::ledger());

		self::assertSame(
			'Utrecht publiceert sinds 2022 [1]. Rotterdam doet dat ook (unverified). Den Haag volgt (unverified).',
			$check->report
		);
		self::assertSame(3, $check->checked);
		self::assertSame(2, $check->failed);
	}

	public function testCaseWhitespaceAndTypographicQuotesAreNormalised(): void {
		$draft = 'Een publiek overzicht [2: “amsterdam noemt het ‘algoritmeregister’ een   publiek overzicht”]. '
			. 'Het register is openbaar [1: "ALGORITMEN in een openbaar register"].';

		$check = (new CitationChecker())->check($draft, self::ledger());

		self::assertSame(0, $check->failed);
		self::assertSame('Een publiek overzicht [2]. Het register is openbaar [1].', $check->report);
	}

	public function testAFullStopInsideAQuotedPassageDoesNotEndTheSentence(): void {
		$ledger = new SourceLedger();
		$ledger->record(['url' => 'https://c.example/', 'content' => 'Zie art. 3 van de wet.'], 'C', new DateTimeImmutable());

		$check = (new CitationChecker())->check('De wet regelt het [1: "art. 3 van de wet"]. Meer volgt [1: "niet gelezen. Echt niet"].', $ledger);

		self::assertSame('De wet regelt het [1]. Meer volgt (unverified).', $check->report);
	}

	public function testTwoFailingCitationsInOneSentenceMarkItOnce(): void {
		$check = (new CitationChecker())->check('Alles klopt [1: "verzonnen"] en meer [2: "ook verzonnen"]!', self::ledger());

		self::assertSame('Alles klopt en meer (unverified)!', $check->report);
		self::assertSame(2, $check->failed);
	}

	public function testHeadingsListsAndTextWithoutCitationsAreLeftAlone(): void {
		$draft = "# Algoritmeregisters\n\n- Utrecht [1: \"zijn algoritmen\"]\n- Zonder bron\n\nGeen citaat hier.";

		$check = (new CitationChecker())->check($draft, self::ledger());

		self::assertSame("# Algoritmeregisters\n\n- Utrecht [1]\n- Zonder bron\n\nGeen citaat hier.", $check->report);
		self::assertSame(1, $check->checked);
	}

	public function testAnEmptyPassageNeverMatches(): void {
		self::assertFalse(CitationChecker::occursIn('  ', 'any text'));
		self::assertTrue(CitationChecker::occursIn('ANY  text', "any\ntext"));
	}

	public function testTheCountsReadAsTheConversationMessageSaysThem(): void {
		$check = (new CitationChecker())->check('Een [2: "verzonnen"].', self::ledger());

		self::assertSame('14 sources, 1 citation checked, 1 unverified', $check->countsLine(14));
	}
}
