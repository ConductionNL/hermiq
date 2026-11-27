<?php

/**
 * Hermiq CodeRunRows.
 *
 * The code runs a run recorded, as a run row on the Runs page shows them: what
 * ran, under which limits, how it ended, and the already capped and redacted
 * outputs `CodeRunTrace` wrote onto the step. Only those fields leave the
 * record; anything else a step carries (its arguments, a raw result) is dropped.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\CodeSandbox
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
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\CodeSandbox;

/**
 * Reads the `codeRun` trace extra off a run's recorded steps for the run row.
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */
final class CodeRunRows {

	/**
	 * The scalar fields of a recorded code run a run row shows.
	 *
	 * @var list<string>
	 */
	public const FIELDS = [
		'language',
		'code',
		'exitCode',
		'durationMs',
		'timedOut',
		'error',
		'stdout',
		'stderr',
		'stdoutTruncated',
		'stderrTruncated',
		'outputWithheld',
	];

	/**
	 * The limits a run row shows.
	 *
	 * @var list<string>
	 */
	public const LIMITS = ['wallSeconds', 'memoryMb'];

	/**
	 * `codeRuns` for a run that ran code; nothing for any other run, so every
	 * other row reads as before.
	 *
	 * @param mixed $steps The run's recorded steps.
	 *
	 * @return array<string, list<array<string, mixed>>> `['codeRuns' => [...]]`, or [].
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
	 */
	public function facts(mixed $steps): array {
		$runs = $this->rows(steps: $steps);
		if ($runs === []) {
			return [];
		}

		return ['codeRuns' => $runs];
	}//end facts()

	/**
	 * One row per step that carries a `codeRun` extra, in step order.
	 *
	 * @param mixed $steps The run's recorded steps.
	 *
	 * @return list<array<string, mixed>>
	 *
	 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
	 */
	public function rows(mixed $steps): array {
		if (is_array($steps) === false) {
			return [];
		}

		$rows = [];
		foreach ($steps as $step) {
			if (is_array($step) === true && is_array($step['codeRun'] ?? null) === true) {
				$rows[] = $this->row(record: $step['codeRun']);
			}
		}

		return $rows;
	}//end rows()

	/**
	 * The shown fields of one recorded code run: scalars only, limits narrowed
	 * to the wall clock and memory.
	 *
	 * @param array<mixed> $record The step's `codeRun` extra.
	 *
	 * @return array<string, mixed>
	 */
	private function row(array $record): array {
		$row = array_filter(
			array_intersect_key($record, array_flip(self::FIELDS)),
			static fn ($value): bool => is_scalar($value)
		);

		$limits = ($record['limits'] ?? null);
		if (is_array($limits) === true) {
			$row['limits'] = array_filter(
				array_intersect_key($limits, array_flip(self::LIMITS)),
				static fn ($value): bool => is_int($value) || is_float($value)
			);
		}

		return $row;
	}//end row()
}//end class
