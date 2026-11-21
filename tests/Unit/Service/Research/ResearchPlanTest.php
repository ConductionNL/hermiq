<?php

/**
 * Unit tests for ResearchPlan (tools-deep-research-report).
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
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Research;

use OCA\Hermiq\Service\Research\ResearchPlan;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchPlan
 */
final class ResearchPlanTest extends TestCase {

	public function testNumberedAndBulletedLinesAreTheSubQuestions(): void {
		$answer = "Here is the plan:\n1. Welke gemeenten?\n2) Wat publiceren ze?\n- Sinds wanneer?\n* Wie beheert het?\nThat is all.";

		self::assertSame(
			['Welke gemeenten?', 'Wat publiceren ze?', 'Sinds wanneer?', 'Wie beheert het?'],
			ResearchPlan::parse($answer, 'Q')
		);
	}

	public function testAtMostEightSubQuestionsAndNoRepeats(): void {
		$lines = [];
		for ($i = 1; $i <= 10; $i++) {
			$lines[] = $i . '. Question ' . $i;
		}

		$lines[] = '11. Question 1';

		$plan = ResearchPlan::parse(implode("\n", $lines), 'Q');

		self::assertCount(8, $plan);
		self::assertSame('Question 8', $plan[7]);
	}

	public function testAnAnswerWithoutSubQuestionsMakesTheQuestionThePlan(): void {
		self::assertSame(['Welke gemeenten?'], ResearchPlan::parse("I cannot plan this.", '  Welke gemeenten?  '));
		self::assertSame(['Welke gemeenten?'], ResearchPlan::parse('', 'Welke gemeenten?'));
	}

	public function testTheMessageHeadsTheNumberedPlan(): void {
		self::assertSame("**Research plan**\n\n1. A\n2. B", ResearchPlan::message(['A', 'B']));
	}

	public function testThePromptCarriesTheAgentsPromptAndTheQuestion(): void {
		$prompt = ResearchPlan::prompt('Welke gemeenten?', 'Schrijf zakelijk.');

		self::assertStringStartsWith("Schrijf zakelijk.\n\n", $prompt);
		self::assertStringContainsString('three to eight sub-questions', $prompt);
		self::assertStringEndsWith('Question: Welke gemeenten?', $prompt);
		self::assertStringStartsWith('You plan a web research.', ResearchPlan::prompt('Q', '  '));
	}
}
