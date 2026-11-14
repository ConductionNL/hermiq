<?php

/**
 * Hermiq QuestionAnswer
 *
 * The rules for answering a question an agent asked in an unattended run:
 * which answers are accepted, how the answer reaches the re-dispatched run,
 * and when an unanswered question expires.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Approval
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Approval;

use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Answer validation, the re-dispatch prompt and the 7-day expiry of a question.
 */
final class QuestionAnswer {

	/**
	 * Days a question waits for an answer before it expires as denied.
	 */
	public const TTL_DAYS = 7;

	/**
	 * The longest answer accepted.
	 */
	public const MAX_ANSWER_LENGTH = 2000;

	/**
	 * The reason recorded on a question that expired unanswered.
	 */
	public const EXPIRED_REASON = 'No answer within 7 days.';

	/**
	 * The answer to store and send on, or a refusal.
	 *
	 * A suggested answer is always accepted; another text only when the
	 * question allows an answer of the person's own, or offers no suggestions.
	 *
	 * @param array<string, mixed> $question The question: `{text, options, allowOther}`.
	 * @param string $answer The answer as given.
	 *
	 * @return string The trimmed answer.
	 *
	 * @throws InvalidArgumentException When the answer is empty, too long or not allowed.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public static function validated(array $question, string $answer): string {
		$answer = trim($answer);
		if ($answer === '') {
			throw new InvalidArgumentException('An answer is required.');
		}

		if (mb_strlen($answer) > self::MAX_ANSWER_LENGTH) {
			throw new InvalidArgumentException('The answer is longer than ' . self::MAX_ANSWER_LENGTH . ' characters.');
		}

		$options = self::options(question: $question);
		if ($options === [] || in_array($answer, $options, true) === true) {
			return $answer;
		}

		if (($question['allowOther'] ?? false) === true) {
			return $answer;
		}

		throw new InvalidArgumentException('Choose one of the suggested answers.');
	}//end validated()

	/**
	 * The prompt the run is dispatched again with: its own prompt, the question and the answer.
	 *
	 * @param string $prompt The run's prompt.
	 * @param string $question The question the agent asked.
	 * @param string $answer The person's answer.
	 *
	 * @return string The prompt for the new run.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public static function promptWithAnswer(string $prompt, string $question, string $answer): string {
		$lines = [rtrim($prompt), ''];
		if (trim($question) !== '') {
			$lines[] = 'You asked: ' . trim($question);
		}

		$lines[] = 'The person answered: ' . trim($answer);

		return ltrim(implode("\n", $lines));
	}//end promptWithAnswer()

	/**
	 * Whether a question asked at a moment has waited longer than the TTL.
	 *
	 * A moment that cannot be read never expires: a question is not denied on a
	 * timestamp nobody can check.
	 *
	 * @param string $requestedAt When the question was asked, ISO 8601.
	 * @param DateTimeImmutable $now The current moment.
	 *
	 * @return bool True when the question has expired.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-a-question-in-an-unattended-run-goes-to-the-reviewer-and-the-run-continues-with-the-answer-req-askhold-002
	 */
	public static function isExpired(string $requestedAt, DateTimeImmutable $now): bool {
		if (trim($requestedAt) === '') {
			return false;
		}

		try {
			$asked = new DateTimeImmutable($requestedAt);
		} catch (Throwable) {
			return false;
		}

		return $asked->add(new DateInterval('P' . self::TTL_DAYS . 'D')) <= $now;
	}//end isExpired()

	/**
	 * The suggested answers of a question, as non-empty strings.
	 *
	 * @param array<string, mixed> $question The question.
	 *
	 * @return list<string> The options.
	 */
	private static function options(array $question): array {
		$raw = $question['options'] ?? [];
		if (is_array($raw) === false) {
			return [];
		}

		$options = [];
		foreach ($raw as $option) {
			if (is_string($option) === true && trim($option) !== '') {
				$options[] = trim($option);
			}
		}

		return $options;
	}//end options()
}//end class
