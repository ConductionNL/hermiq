<?php

/**
 * Hermiq AskPersonQuestion
 *
 * The question an agent asks a person through `hermiq.askPerson`, checked.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use InvalidArgumentException;

/**
 * A question of at most 500 characters, up to four suggested answers of at most
 * 80 characters each, and whether the person may answer in their own words.
 *
 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
 */
final class AskPersonQuestion {

	public const MAX_QUESTION = 500;

	public const MAX_OPTIONS = 4;

	public const MAX_OPTION = 80;

	/**
	 * The question.
	 *
	 * @var string
	 */
	public readonly string $text;

	/**
	 * The suggested answers.
	 *
	 * @var list<string>
	 */
	public readonly array $options;

	/**
	 * Whether a free-text answer is offered.
	 *
	 * @var boolean
	 */
	public readonly bool $allowOther;

	/**
	 * The question in a tool call's arguments, checked.
	 *
	 * Without suggested answers the person can only answer in their own words,
	 * so `allowOther` is then always on.
	 *
	 * @param array<string, mixed> $arguments The decoded arguments.
	 *
	 * @throws InvalidArgumentException With the rule the call broke.
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
	 */
	public function __construct(array $arguments) {
		$text = ($arguments['question'] ?? null);
		if (is_string($text) === false || trim($text) === '') {
			throw new InvalidArgumentException('askPerson needs a question.');
		}

		if (mb_strlen(trim($text)) > self::MAX_QUESTION) {
			throw new InvalidArgumentException(sprintf('The question is longer than %d characters.', self::MAX_QUESTION));
		}

		$this->text = trim($text);
		$this->options = self::options(raw: ($arguments['options'] ?? []));
		$this->allowOther = (($arguments['allowOther'] ?? false) === true || $this->options === []);
	}//end __construct()

	/**
	 * The question as the chat and the trace carry it.
	 *
	 * @return array{text: string, options: list<string>, allowOther: bool}
	 *
	 * @spec openspec/changes/oversight-ask-and-hold/specs/human-approval-gate/spec.md#requirement-an-agent-can-ask-a-person-a-question-with-suggested-answers-req-askhold-001
	 */
	public function toArray(): array {
		return ['text' => $this->text, 'options' => $this->options, 'allowOther' => $this->allowOther];
	}//end toArray()

	/**
	 * The suggested answers, checked.
	 *
	 * @param mixed $raw The `options` argument.
	 *
	 * @return list<string> The answers.
	 *
	 * @throws InvalidArgumentException For more than four answers, or an empty or too long one.
	 */
	private static function options(mixed $raw): array {
		if (is_array($raw) === false) {
			throw new InvalidArgumentException('options must be a list of suggested answers.');
		}

		if (count($raw) > self::MAX_OPTIONS) {
			throw new InvalidArgumentException(sprintf('Offer at most %d suggested answers.', self::MAX_OPTIONS));
		}

		$options = [];
		foreach ($raw as $option) {
			if (is_string($option) === false || trim($option) === '' || mb_strlen(trim($option)) > self::MAX_OPTION) {
				throw new InvalidArgumentException(sprintf('Each suggested answer is text of 1 to %d characters.', self::MAX_OPTION));
			}

			$options[] = trim($option);
		}

		return $options;
	}//end options()
}//end class
