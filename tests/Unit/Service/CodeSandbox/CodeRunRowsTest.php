<?php

/**
 * What a run row shows of the code runs a run recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Service\CodeSandbox\CodeRunRows;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\CodeSandbox\CodeRunRows
 */
class CodeRunRowsTest extends TestCase {

	/**
	 * Each step with a `codeRun` extra becomes one row, in step order, with the
	 * code, the limits, the exit code and the recorded outputs; the step's
	 * arguments and anything not on the list stay on the record.
	 *
	 * @return void
	 */
	public function testEachCodeStepBecomesOneRowWithOnlyTheShownFields(): void {
		$steps = [
			['seq' => 1, 'type' => 'llm', 'name' => 'answer'],
			[
				'seq' => 2,
				'type' => 'tool',
				'name' => 'hermiq.runCode',
				'arguments' => ['code' => 'print(1)', 'secret' => 'x'],
				'codeRun' => [
					'language' => 'python',
					'code' => 'print(1 + 1)',
					'limits' => ['wallSeconds' => 10, 'memoryMb' => 256, 'raw' => 'dropped'],
					'exitCode' => 0,
					'durationMs' => 120,
					'timedOut' => false,
					'stdout' => "2\n",
					'stdoutTruncated' => false,
					'stderr' => '',
					'stderrTruncated' => false,
					'result' => ['raw' => 'dropped'],
				],
			],
			[
				'seq' => 3,
				'type' => 'tool',
				'name' => 'hermiq.runCode',
				'codeRun' => ['language' => 'python', 'code' => 'while True: pass', 'timedOut' => true, 'outputWithheld' => true],
			],
		];

		$this->assertSame(
			[
				'codeRuns' => [
					[
						'language' => 'python',
						'code' => 'print(1 + 1)',
						'exitCode' => 0,
						'durationMs' => 120,
						'timedOut' => false,
						'stdout' => "2\n",
						'stdoutTruncated' => false,
						'stderr' => '',
						'stderrTruncated' => false,
						'limits' => ['wallSeconds' => 10, 'memoryMb' => 256],
					],
					['language' => 'python', 'code' => 'while True: pass', 'timedOut' => true, 'outputWithheld' => true],
				],
			],
			(new CodeRunRows())->facts(steps: $steps)
		);
	}//end testEachCodeStepBecomesOneRowWithOnlyTheShownFields()

	/**
	 * A run that ran no code, or whose steps are not a list, adds nothing to its row.
	 *
	 * @return void
	 */
	public function testARunWithoutCodeAddsNothing(): void {
		$this->assertSame([], (new CodeRunRows())->facts(steps: [['seq' => 1, 'type' => 'llm', 'name' => 'answer']]));
		$this->assertSame([], (new CodeRunRows())->facts(steps: null));
		$this->assertSame([], (new CodeRunRows())->facts(steps: [['codeRun' => 'not a record']]));
	}//end testARunWithoutCodeAddsNothing()

	/**
	 * A non-scalar value in a shown field, or a non-numeric limit, never reaches the row.
	 *
	 * @return void
	 */
	public function testNonScalarValuesAreDropped(): void {
		$rows = (new CodeRunRows())->rows(
			steps: [['codeRun' => ['code' => ['nested'], 'stdout' => 'ok', 'limits' => ['wallSeconds' => '10', 'memoryMb' => 128]]]]
		);

		$this->assertSame([['stdout' => 'ok', 'limits' => ['memoryMb' => 128]]], $rows);
	}//end testNonScalarValuesAreDropped()
}//end class
