<?php

/**
 * Hermiq code run trace (tools-code-sandbox D6).
 *
 * What the run record keeps of one `hermiq.runCode` call: the code, the
 * language, the limits, the exit code, the duration, whether it timed out,
 * and stdout and stderr each cut to 4 KB and redacted before they are
 * persisted. Redaction is RedactionService's forced audit pass plus e-mail
 * addresses, which that pass leaves alone. Without a redaction service the
 * outputs are withheld rather than kept unredacted. The full output goes back
 * to the model, never into the trace.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

use OCA\Hermiq\Service\RedactionService;

/**
 * Builds the trace extra of a code run.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */
final class CodeRunTrace {

	/**
	 * How much of each output the trace keeps, in bytes.
	 *
	 * @var int
	 */
	public const OUTPUT_CAP_BYTES = 4096;

	/**
	 * What replaces a redacted e-mail address.
	 *
	 * @var string
	 */
	public const MARKER = '***';

	/**
	 * The e-mail address pattern.
	 *
	 * @var string
	 */
	private const EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/';

	/**
	 * The `codeRun` trace extra of one call.
	 *
	 * @param array<string, mixed>  $arguments The call's arguments.
	 * @param mixed                 $result    The tool's decoded result.
	 * @param RedactionService|null $redaction The redaction pass; null withholds the outputs.
	 *
	 * @return array{codeRun: array<string, mixed>}
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#scenario-an-auditor-reads-what-ran
	 */
	public static function extra(array $arguments, mixed $result, ?RedactionService $redaction): array {
		if (is_array($result) === false) {
			$result = [];
		}

		$limits = ($result['limits'] ?? null);
		if (is_array($limits) === false) {
			$limits = ['wallSeconds' => ($arguments['timeoutSeconds'] ?? null), 'memoryMb' => ($arguments['memoryMb'] ?? null)];
		}

		$record = [
			'language' => (string)($result['language'] ?? ($arguments['language'] ?? '')),
			'code' => (string)($arguments['code'] ?? ''),
			'limits' => $limits,
			'exitCode' => self::intOrNull(value: ($result['exitCode'] ?? null)),
			'durationMs' => self::intOrNull(value: ($result['durationMs'] ?? null)),
			'timedOut' => (($result['timedOut'] ?? false) === true),
		];

		if (isset($result['error']) === true && is_array($result['error']) === true) {
			$record['error'] = (string)($result['error']['code'] ?? 'error');
		}

		if ($redaction === null) {
			$record['outputWithheld'] = true;
			return ['codeRun' => $record];
		}

		foreach (['stdout', 'stderr'] as $stream) {
			$text = (string)($result[$stream] ?? '');
			$record[$stream] = self::cleaned(text: $text, redaction: $redaction);
			$record[$stream . 'Truncated'] = strlen($text) > self::OUTPUT_CAP_BYTES;
		}

		return ['codeRun' => $record];
	}//end extra()

	/**
	 * One output cut to the cap, then redacted.
	 *
	 * Cut first so a pattern cannot be split into an unredacted half by the
	 * cut after it was matched; redacting the cut text still masks it.
	 *
	 * @param string           $text      The output.
	 * @param RedactionService $redaction The redaction pass.
	 *
	 * @return string
	 */
	private static function cleaned(string $text, RedactionService $redaction): string {
		$cut = mb_strcut($text, 0, self::OUTPUT_CAP_BYTES, 'UTF-8');

		return (string)preg_replace(self::EMAIL, self::MARKER, $redaction->redact($cut));
	}//end cleaned()

	/**
	 * An integer, or null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int|null
	 */
	private static function intOrNull(mixed $value): ?int {
		if (is_int($value) === false) {
			return null;
		}

		return $value;
	}//end intOrNull()
}//end class
