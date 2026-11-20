<?php

/**
 * Hermiq ComputerActionRecord
 *
 * What the run keeps of a computer tool call (tools-browser-and-computer-use D6): the
 * machine, the action, the coordinates, the typed text after redaction, the keys and
 * the approval id, and a screenshot only as its SHA-256 and size. A screen can show
 * anything, so the image itself is never part of a trace step or of what is streamed
 * to the person's browser as the tool's result.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ComputerUse
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
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ComputerUse;

use OCA\Hermiq\Mcp\ComputerToolDescriptors;

/**
 * The trace record of a computer tool call.
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
 */
final class ComputerActionRecord {

	/**
	 * The trace extra for a computer tool call, or [] for any other tool.
	 *
	 * @param string               $toolId     The dotted tool id.
	 * @param array<string, mixed> $arguments  The call's arguments.
	 * @param mixed                $result     The tool's result.
	 * @param string|null          $approvalId The approval the action ran under, if any.
	 * @param callable(string): string $redact The redaction applied to typed text.
	 *
	 * @return array<string, mixed> `['computer' => [...]]`, or [].
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
	 */
	public static function traceExtra(string $toolId, array $arguments, mixed $result, ?string $approvalId, callable $redact): array {
		if (in_array($toolId, ComputerToolDescriptors::IDS, true) === false) {
			return [];
		}

		$record = [
			'target' => self::scalar(value: ($arguments['target'] ?? '')),
			'action' => self::action(toolId: $toolId, arguments: $arguments),
		];
		$record = array_merge($record, self::actionFields(toolId: $toolId, arguments: $arguments, redact: $redact));

		if ($approvalId !== null && $approvalId !== '') {
			$record['approvalId'] = $approvalId;
		}

		$screenshot = self::screenshot(result: $result);
		if ($screenshot !== null) {
			$record['screenshot'] = $screenshot;
		}

		return ['computer' => $record];
	}//end traceExtra()

	/**
	 * The fields one kind of computer action adds to its record: the point of a
	 * click, the redacted text of a type action, the keys of a key action.
	 *
	 * @param string               $toolId    The computer tool id.
	 * @param array<string, mixed> $arguments The call's arguments.
	 * @param callable             $redact    Redacts typed text before it is stored.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use
	 */
	private static function actionFields(string $toolId, array $arguments, callable $redact): array {
		if ($toolId === ComputerToolDescriptors::CLICK) {
			return [
				'x' => self::coordinate(value: ($arguments['x'] ?? null)),
				'y' => self::coordinate(value: ($arguments['y'] ?? null)),
			];
		}

		if ($toolId === ComputerToolDescriptors::TYPE) {
			return ['text' => $redact(self::scalar(value: ($arguments['text'] ?? '')))];
		}

		if ($toolId === ComputerToolDescriptors::KEY) {
			return ['keys' => self::scalar(value: ($arguments['keys'] ?? ''))];
		}

		return [];
	}//end actionFields()

	/**
	 * A tool result with any screenshot replaced by its hash and size.
	 *
	 * @param mixed $result The tool's result.
	 *
	 * @return mixed The result without the image bytes.
	 *
	 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-the-run-records-actions-and-never-stores-screenshots-req-computer-004
	 */
	public static function withoutImage(mixed $result): mixed {
		$screenshot = self::screenshot(result: $result);
		if ($screenshot === null) {
			return $result;
		}

		$result['image'] = $screenshot;

		return $result;
	}//end withoutImage()

	/**
	 * The hash and size of the screenshot a result carries, or null.
	 *
	 * @param mixed $result The tool's result.
	 *
	 * @return array{sha256: string, bytes: int}|null
	 */
	private static function screenshot(mixed $result): ?array {
		if (is_array($result) === false || is_string($result['image'] ?? null) === false || $result['image'] === '') {
			return null;
		}

		$bytes = base64_decode($result['image'], true);
		if ($bytes === false) {
			return null;
		}

		return ['sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
	}//end screenshot()

	/**
	 * The recorded action name.
	 *
	 * @param string               $toolId    The tool.
	 * @param array<string, mixed> $arguments The arguments.
	 *
	 * @return string `screenshot`, the click kind, `type` or `key`.
	 */
	private static function action(string $toolId, array $arguments): string {
		return match ($toolId) {
			ComputerToolDescriptors::CLICK => self::scalar(value: ($arguments['kind'] ?? 'click')),
			ComputerToolDescriptors::TYPE => 'type',
			ComputerToolDescriptors::KEY => 'key',
			default => 'screenshot',
		};
	}//end action()

	/**
	 * A coordinate as given, or null when it is not a whole number.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private static function coordinate(mixed $value): ?int {
		if (is_int($value) === false) {
			return null;
		}

		return $value;
	}//end coordinate()

	/**
	 * A scalar argument as text; anything else as empty text.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private static function scalar(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end scalar()
}//end class
