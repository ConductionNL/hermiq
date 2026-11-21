<?php

/**
 * Hermiq ResearchPlan.
 *
 * The plan phase of a research run (design D2): the prompt that asks the
 * model for three to eight sub-questions, the reading of its answer, and the
 * "Research plan" message the conversation shows before any search.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Research
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

namespace OCA\Hermiq\Service\Research;

/**
 * Asks for, reads and words a research plan.
 *
 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
 */
final class ResearchPlan {

	/**
	 * The most sub-questions a plan keeps.
	 */
	public const MAX_QUESTIONS = 8;

	/**
	 * The heading of the plan message.
	 */
	public const HEADING = 'Research plan';

	/**
	 * The longest sub-question kept, in characters.
	 */
	private const QUESTION_LENGTH = 300;

	/**
	 * The prompt that asks for the plan.
	 *
	 * @param string $question    The user's research question.
	 * @param string $agentPrompt The agent owner's prompt, '' when it has none.
	 *
	 * @return string The prompt.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public static function prompt(string $question, string $agentPrompt): string {
		$lines = [];
		if (trim($agentPrompt) !== '') {
			$lines[] = trim($agentPrompt);
			$lines[] = '';
		}

		$lines[] = 'You plan a web research. Split the question below into three to eight sub-questions that together answer it.';
		$lines[] = 'Answer with the sub-questions only, one per line, numbered 1., 2., 3. and so on. Write them in the language of the question.';
		$lines[] = '';
		$lines[] = 'Question: ' . trim($question);

		return implode("\n", $lines);
	}//end prompt()

	/**
	 * The sub-questions in the model's answer, at most eight.
	 *
	 * A numbered or bulleted line is a sub-question; its marker is removed. When
	 * no line reads as one, the question itself is the plan, so a run never
	 * gathers on nothing.
	 *
	 * @param string $answer   The model's answer.
	 * @param string $question The user's research question, the fallback plan.
	 *
	 * @return list<string> The sub-questions.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public static function parse(string $answer, string $question): array {
		$questions = [];
		$lines = preg_split('/\R/u', $answer);
		if ($lines === false) {
			$lines = [];
		}

		foreach ($lines as $line) {
			$matched = preg_match('/^\s*(?:\d{1,2}[.)]|[-*\x{2022}])\s+(.+)$/u', $line, $match);
			if ($matched !== 1) {
				continue;
			}

			$text = trim(mb_substr(trim($match[1]), 0, self::QUESTION_LENGTH));
			if ($text !== '' && in_array($text, $questions, true) === false) {
				$questions[] = $text;
			}
		}

		if ($questions === []) {
			return [trim($question)];
		}

		return array_slice($questions, 0, self::MAX_QUESTIONS);
	}//end parse()

	/**
	 * The "Research plan" message: the heading and the numbered sub-questions.
	 *
	 * @param list<string> $plan The sub-questions.
	 *
	 * @return string The message, in Markdown.
	 *
	 * @spec openspec/changes/tools-deep-research-report/specs/deep-research/spec.md#requirement-a-user-can-start-deep-research-and-sees-the-plan-req-research-001
	 */
	public static function message(array $plan): string {
		$lines = ['**' . self::HEADING . '**', ''];
		foreach (array_values($plan) as $index => $question) {
			$lines[] = ($index + 1) . '. ' . $question;
		}

		return implode("\n", $lines);
	}//end message()
}//end class
