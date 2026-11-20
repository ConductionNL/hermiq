<?php

/**
 * Unit tests for QuestionAnswer (oversight-ask-and-hold): which answers a
 * question accepts, the prompt a run continues with, and the 7-day expiry.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service
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
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Approval;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Hermiq\Service\Approval\QuestionAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionAnswer::class)]
class QuestionAnswerTest extends TestCase {

	private const QUESTION = ['text' => 'Close the three duplicate reports?', 'options' => ['Yes, close them', 'No, keep them'], 'allowOther' => false];

	public function testASuggestedAnswerIsAcceptedTrimmed(): void {
		$this->assertSame('Yes, close them', QuestionAnswer::validated(question: self::QUESTION, answer: '  Yes, close them '));
	}

	public function testAnotherAnswerIsRefusedWhenOnlySuggestionsAreAllowed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Choose one of the suggested answers.');

		QuestionAnswer::validated(question: self::QUESTION, answer: 'Only the first one');
	}

	public function testAnotherAnswerIsAcceptedWhenTheQuestionAllowsIt(): void {
		$question = ['allowOther' => true] + self::QUESTION;

		$this->assertSame('Only the first one', QuestionAnswer::validated(question: $question, answer: 'Only the first one'));
	}

	public function testAQuestionWithoutSuggestionsTakesAnyAnswer(): void {
		$this->assertSame('Houten', QuestionAnswer::validated(question: ['text' => 'Which municipality?', 'options' => []], answer: 'Houten'));
	}

	public function testAnEmptyAnswerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('An answer is required.');

		QuestionAnswer::validated(question: self::QUESTION, answer: '   ');
	}

	public function testATooLongAnswerIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);

		QuestionAnswer::validated(question: ['options' => []], answer: str_repeat('a', QuestionAnswer::MAX_ANSWER_LENGTH + 1));
	}

	public function testThePromptCarriesTheQuestionAndTheAnswer(): void {
		$this->assertSame(
			"Tidy the reports.\n\nYou asked: Close them?\nThe person answered: Yes",
			QuestionAnswer::promptWithAnswer(prompt: 'Tidy the reports.', question: ' Close them? ', answer: ' Yes ')
		);
	}

	public function testThePromptLeavesOutAnEmptyQuestion(): void {
		$this->assertSame('The person answered: Yes', QuestionAnswer::promptWithAnswer(prompt: '', question: '', answer: 'Yes'));
	}

	public function testAQuestionExpiresAfterSevenDays(): void {
		$now = new DateTimeImmutable('2026-11-14T08:00:00+00:00');

		$this->assertTrue(QuestionAnswer::isExpired(requestedAt: '2026-11-07T08:00:00+00:00', now: $now));
		$this->assertFalse(QuestionAnswer::isExpired(requestedAt: '2026-11-07T08:00:01+00:00', now: $now));
	}

	public function testAnUnreadableMomentNeverExpires(): void {
		$now = new DateTimeImmutable('2026-11-14T08:00:00+00:00');

		$this->assertFalse(QuestionAnswer::isExpired(requestedAt: '', now: $now));
		$this->assertFalse(QuestionAnswer::isExpired(requestedAt: 'not a date', now: $now));
	}
}//end class
