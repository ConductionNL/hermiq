<?php

/**
 * Hermiq StartFields.
 *
 * The fields an agent asks a person to fill in before a conversation starts
 * (`Agent.startFields`), and the answers stored on the session
 * (`agentsession.startValues`). Pure functions over plain arrays: which fields
 * an agent declares, which answers are acceptable, and the answer each field
 * contributes to the instructions (`{{field.<key>}}`).
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
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
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

/**
 * Start fields and their answers.
 *
 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
 */
final class StartFields {

	/**
	 * The field types: short text, long text, choice, number, date.
	 *
	 * @var array<int, string>
	 */
	public const TYPES = ['text', 'paragraph', 'select', 'number', 'date'];

	/**
	 * At most this many fields per agent.
	 */
	public const MAX_FIELDS = 10;

	/**
	 * A field key: lower case, starts with a letter, at most 32 characters.
	 */
	public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

	/**
	 * The longest answer kept, in characters.
	 */
	public const MAX_ANSWER_LENGTH = 2000;

	/**
	 * The agent's declared fields, with malformed entries left out.
	 *
	 * @param array<string, mixed> $agentData The agent's data.
	 *
	 * @return array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string}>
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public static function of(array $agentData): array {
		$fields = [];
		$seen = [];
		foreach ((array)($agentData['startFields'] ?? []) as $raw) {
			$field = self::normalise(raw: $raw);
			if ($field === null || isset($seen[$field['key']]) === true) {
				continue;
			}

			$seen[$field['key']] = true;
			$fields[] = $field;
			if (count($fields) === self::MAX_FIELDS) {
				break;
			}
		}

		return $fields;
	}//end of()

	/**
	 * Why the answers are not acceptable, per field key; empty when they are.
	 *
	 * @param array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string}> $fields The fields.
	 * @param array<string, mixed>                                                                                                        $values The answers.
	 *
	 * @return array<string, string> Field key => reason.
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public static function problems(array $fields, array $values): array {
		$problems = [];
		foreach ($fields as $field) {
			$answer = self::answerText(value: ($values[$field['key']] ?? null));
			$reason = self::problemWith(field: $field, answer: $answer);
			if ($reason !== null) {
				$problems[$field['key']] = $reason;
			}
		}

		return $problems;
	}//end problems()

	/**
	 * The answers to keep: declared keys only, as trimmed text, empty ones left out.
	 *
	 * @param array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string}> $fields The fields.
	 * @param array<string, mixed>                                                                                                        $values The answers.
	 *
	 * @return array<string, string>
	 */
	public static function clean(array $fields, array $values): array {
		$clean = [];
		foreach ($fields as $field) {
			$answer = self::answerText(value: ($values[$field['key']] ?? null));
			if ($answer !== '') {
				$clean[$field['key']] = $answer;
			}
		}

		return $clean;
	}//end clean()

	/**
	 * What each field contributes to the instructions: its answer, else its
	 * default, else an empty string.
	 *
	 * @param array<int, array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string}> $fields The fields.
	 * @param array<string, mixed>                                                                                                        $values The stored answers.
	 *
	 * @return array<string, string> Field key => value.
	 *
	 * @spec openspec/changes/agents-instruction-variables/specs/agent-management-ui/spec.md#requirement-an-agent-can-ask-for-fields-before-a-conversation-starts-req-agvar-002
	 */
	public static function answersFor(array $fields, array $values): array {
		$answers = [];
		foreach ($fields as $field) {
			$answer = self::answerText(value: ($values[$field['key']] ?? null));
			if ($answer === '') {
				$answer = $field['default'];
			}

			if ($field['type'] !== 'paragraph') {
				$answer = (string)preg_replace('/\s*[\r\n]+\s*/', ' ', $answer);
			}

			$answers[$field['key']] = $answer;
		}

		return $answers;
	}//end answersFor()

	/**
	 * One declared field, normalised, or null when it cannot be used.
	 *
	 * @param mixed $raw The stored entry.
	 *
	 * @return array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string}|null
	 */
	private static function normalise(mixed $raw): ?array {
		if (is_array($raw) === false) {
			return null;
		}

		$key = (string)($raw['key'] ?? '');
		$type = (string)($raw['type'] ?? 'text');
		if (preg_match(self::KEY_PATTERN, $key) !== 1 || in_array($type, self::TYPES, true) === false) {
			return null;
		}

		$options = [];
		foreach ((array)($raw['options'] ?? []) as $option) {
			if (is_scalar($option) === true && trim((string)$option) !== '') {
				$options[] = trim((string)$option);
			}
		}

		$label = trim((string)($raw['label'] ?? ''));
		if ($label === '') {
			$label = $key;
		}

		return [
			'key' => $key,
			'label' => $label,
			'type' => $type,
			'options' => $options,
			'required' => (($raw['required'] ?? false) === true),
			'default' => self::answerText(value: ($raw['default'] ?? null)),
		];
	}//end normalise()

	/**
	 * Why one answer is not acceptable, or null.
	 *
	 * @param array{key: string, label: string, type: string, options: array<int, string>, required: bool, default: string} $field  The field.
	 * @param string                                                                                                         $answer The answer as text.
	 *
	 * @return string|null
	 */
	private static function problemWith(array $field, string $answer): ?string {
		if ($answer === '') {
			if ($field['required'] === true) {
				return 'required';
			}

			return null;
		}

		return match ($field['type']) {
			'select' => (in_array($answer, $field['options'], true) === true) ? null : 'not one of the options',
			'number' => (is_numeric($answer) === true) ? null : 'not a number',
			'date' => (self::isDate(value: $answer) === true) ? null : 'not a date (YYYY-MM-DD)',
			default => null,
		};
	}//end problemWith()

	/**
	 * Whether the text is a calendar date written YYYY-MM-DD.
	 *
	 * @param string $value The text.
	 *
	 * @return bool
	 */
	private static function isDate(string $value): bool {
		if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
			return false;
		}

		return checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]);
	}//end isDate()

	/**
	 * An answer as trimmed text of bounded length; a non-scalar is no answer.
	 *
	 * @param mixed $value The answer.
	 *
	 * @return string
	 */
	private static function answerText(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		if (is_bool($value) === true) {
			return '';
		}

		return mb_substr(trim((string)$value), 0, self::MAX_ANSWER_LENGTH);
	}//end answerText()
}//end class
