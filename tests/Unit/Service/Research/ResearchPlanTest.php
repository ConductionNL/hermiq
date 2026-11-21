<?php

/**
 * Unit tests for ResearchPlan and ResearchReport (tools-deep-research-report).
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

use DateTimeImmutable;
use OCA\Hermiq\Service\Research\CitationCheck;
use OCA\Hermiq\Service\Research\ResearchPlan;
use OCA\Hermiq\Service\Research\ResearchReport;
use OCA\Hermiq\Service\Research\ResearchRunState;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Research\ResearchPlan
 * @covers \OCA\Hermiq\Service\Research\ResearchReport
 * @uses   \OCA\Hermiq\Service\Research\ResearchRunState
 * @uses   \OCA\Hermiq\Service\Research\ResearchCaps
 * @uses   \OCA\Hermiq\Service\Research\SourceLedger
 * @uses   \OCA\Hermiq\Service\Research\CitationCheck
 */
final class ResearchPlanTest extends TestCase {

	/**
	 * Numbered and bulleted lines are the sub-questions; other lines are not.
	 *
	 * @return void
	 */
	public function testNumberedAndBulletedLinesAreTheSubQuestions(): void {
		$answer = "Here is the plan:\n1. Welke gemeenten?\n2) Wat publiceren ze?\r\n- Sinds wanneer?\n* Wie beheert het?\nThat is all.";

		self::assertSame(
			expected: ['Welke gemeenten?', 'Wat publiceren ze?', 'Sinds wanneer?', 'Wie beheert het?'],
			actual: (new ResearchPlan())->parse(answer: $answer, question: 'Q')
		);
	}//end testNumberedAndBulletedLinesAreTheSubQuestions()

	/**
	 * A plan keeps at most eight sub-questions and no repeats.
	 *
	 * @return void
	 */
	public function testAtMostEightSubQuestionsAndNoRepeats(): void {
		$lines = ['1. Question 1', '2. Question 1'];
		for ($i = 2; $i <= 10; $i++) {
			$lines[] = $i . '. Question ' . $i;
		}

		$plan = (new ResearchPlan())->parse(answer: implode("\n", $lines), question: 'Q');

		self::assertCount(expectedCount: 8, haystack: $plan);
		self::assertSame(expected: 'Question 1', actual: $plan[0]);
		self::assertSame(expected: 'Question 8', actual: $plan[7]);
	}//end testAtMostEightSubQuestionsAndNoRepeats()

	/**
	 * An answer without sub-questions makes the question itself the plan.
	 *
	 * @return void
	 */
	public function testAnAnswerWithoutSubQuestionsMakesTheQuestionThePlan(): void {
		$planner = new ResearchPlan();

		self::assertSame(expected: ['Welke gemeenten?'], actual: $planner->parse(answer: 'I cannot plan this.', question: '  Welke gemeenten?  '));
		self::assertSame(expected: ['Welke gemeenten?'], actual: $planner->parse(answer: '', question: 'Welke gemeenten?'));
	}//end testAnAnswerWithoutSubQuestionsMakesTheQuestionThePlan()

	/**
	 * The message heads the numbered plan; the prompt asks for three to eight.
	 *
	 * @return void
	 */
	public function testTheMessageAndThePrompt(): void {
		$planner = new ResearchPlan();

		self::assertSame(expected: "**Research plan**\n\n1. A\n2. B", actual: $planner->message(plan: ['A', 'B']));
		self::assertStringContainsString(needle: 'three to eight sub-questions', haystack: $planner->prompt(question: 'Q'));
		self::assertStringEndsWith(suffix: 'Question: Welke gemeenten?', string: $planner->prompt(question: ' Welke gemeenten? '));
	}//end testTheMessageAndThePrompt()

	/**
	 * The saved report leads with the cap sentence, then the checked text, then the sources.
	 *
	 * @return void
	 */
	public function testTheReportLeadsWithTheCapSentenceAndEndsWithTheSources(): void {
		$state = $this->state(ledger: [['url' => 'https://a', 'title' => 'A', 'text' => 'alpha', 'fetchedAt' => 'now']]);
		$state->capReason = 'This report stopped early: the source limit of 1 was reached.';

		$report = (new ResearchReport())->render(state: $state, check: new CitationCheck(report: "# Title\n\nText [1].", checked: 1, failed: 0));

		self::assertStringStartsWith(prefix: "This report stopped early: the source limit of 1 was reached.\n\n# Title", string: $report);
		self::assertStringEndsWith(suffix: "## Sources\n\n1. A, <https://a>, read now\n", string: $report);
	}//end testTheReportLeadsWithTheCapSentenceAndEndsWithTheSources()

	/**
	 * The summary is the first five lines of text, headings left out.
	 *
	 * @return void
	 */
	public function testTheSummaryIsTheFirstFiveLinesOfText(): void {
		$summary = (new ResearchReport())->summary(checked: "# Title\n\nOne\r\nTwo\n## Part\nThree\nFour\nFive\nSix");

		self::assertSame(expected: ['One', 'Two', 'Three', 'Four', 'Five'], actual: $summary);
	}//end testTheSummaryIsTheFirstFiveLinesOfText()

	/**
	 * The report prompt carries every numbered source, and says so when none was read.
	 *
	 * @return void
	 */
	public function testTheReportPromptCarriesTheSources(): void {
		$report = new ResearchReport();

		$withSources = $report->prompt(state: $this->state(ledger: [['url' => 'https://a', 'title' => 'A', 'text' => 'alpha']]));
		self::assertStringContainsString(needle: "[1] A <https://a>\nalpha", haystack: $withSources);
		self::assertStringContainsString(needle: 'Question: Q', haystack: $withSources);

		self::assertStringContainsString(needle: 'No source could be read.', haystack: $report->prompt(state: $this->state(ledger: [])));
	}//end testTheReportPromptCarriesTheSources()

	/**
	 * A run with this ledger.
	 *
	 * @param list<array<string, string>> $ledger The stored ledger.
	 *
	 * @return ResearchRunState
	 */
	private function state(array $ledger): ResearchRunState {
		return new ResearchRunState(handoffId: 'r-1', handoff: ['label' => 'Q', 'ledger' => $ledger, 'plan' => ['A?', 7]], now: new DateTimeImmutable());
	}//end state()
}//end class
