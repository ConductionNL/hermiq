<?php

/**
 * What the run record keeps of a code run.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-code-sandbox/specs/code-sandbox/spec.md#requirement-every-code-run-is-on-the-run-record-redacted-and-capped-req-sandbox-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\CodeSandbox;

use OCA\Hermiq\Service\CodeSandbox\CodeRunTrace;
use OCA\Hermiq\Service\RedactionService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\CodeSandbox\CodeRunTrace
 */
class CodeRunTraceTest extends TestCase {

	/**
	 * A redaction pass that masks one token, recording what it was given.
	 *
	 * @param array<int, string> $seen What the pass was given.
	 *
	 * @return RedactionService
	 */
	private function redaction(array &$seen): RedactionService {
		$redaction = $this->createMock(RedactionService::class);
		$redaction->method('redact')->willReturnCallback(
			static function (string $text) use (&$seen): string {
				$seen[] = $text;
				return str_replace('sk-live-123', '***', $text);
			}
		);

		return $redaction;
	}//end redaction()

	/**
	 * Spec scenario "An auditor reads what ran": the code, its limits and
	 * outcome are kept, each output is cut to 4 KB and redacted, and e-mail
	 * addresses are masked.
	 *
	 * @return void
	 */
	public function testAnAuditorReadsWhatRan(): void {
		$seen = [];
		$stdout = 'mail jan.devries@example.nl key sk-live-123 ' . str_repeat('a', 5000);
		$extra = CodeRunTrace::extra(
			arguments: ['language' => 'python', 'code' => 'print(rows)', 'timeoutSeconds' => 20],
			result: [
				'exitCode' => 0,
				'stdout' => $stdout,
				'stderr' => 'warn: info@gemeente.nl',
				'durationMs' => 120,
				'timedOut' => false,
				'limits' => ['wallSeconds' => 20, 'memoryMb' => 512],
				'language' => 'python',
			],
			redaction: $this->redaction(seen: $seen)
		);

		$run = $extra['codeRun'];
		$this->assertSame('python', $run['language']);
		$this->assertSame('print(rows)', $run['code']);
		$this->assertSame(['wallSeconds' => 20, 'memoryMb' => 512], $run['limits']);
		$this->assertSame(0, $run['exitCode']);
		$this->assertSame(120, $run['durationMs']);
		$this->assertFalse($run['timedOut']);
		$this->assertStringStartsWith('mail *** key *** aaa', $run['stdout']);
		$this->assertLessThanOrEqual(CodeRunTrace::OUTPUT_CAP_BYTES, strlen($run['stdout']));
		$this->assertTrue($run['stdoutTruncated']);
		$this->assertSame('warn: ***', $run['stderr']);
		$this->assertFalse($run['stderrTruncated']);
		$this->assertSame(CodeRunTrace::OUTPUT_CAP_BYTES, strlen($seen[0]), 'The output is cut before it is redacted.');
	}//end testAnAuditorReadsWhatRan()

	/**
	 * Without a redaction pass the outputs are withheld, not kept raw.
	 *
	 * @return void
	 */
	public function testWithoutRedactionTheOutputsAreWithheld(): void {
		$extra = CodeRunTrace::extra(
			arguments: ['language' => 'node', 'code' => 'x'],
			result: ['exitCode' => 1, 'stdout' => 'jan@example.nl', 'stderr' => 'boom', 'timedOut' => true],
			redaction: null
		);

		$this->assertSame(
			[
				'codeRun' => [
					'language' => 'node',
					'code' => 'x',
					'limits' => ['wallSeconds' => null, 'memoryMb' => null],
					'exitCode' => 1,
					'durationMs' => null,
					'timedOut' => true,
					'outputWithheld' => true,
				],
			],
			$extra
		);
	}//end testWithoutRedactionTheOutputsAreWithheld()

	/**
	 * A refused call records its error code and no outcome.
	 *
	 * @return void
	 */
	public function testARefusedCallRecordsItsErrorCode(): void {
		$seen = [];
		$extra = CodeRunTrace::extra(
			arguments: ['language' => 'python', 'code' => 'print(1)', 'memoryMb' => 256],
			result: ['error' => ['code' => 'code_execution_not_enabled', 'message' => 'Running code must be enabled.']],
			redaction: $this->redaction(seen: $seen)
		);

		$this->assertSame('code_execution_not_enabled', $extra['codeRun']['error']);
		$this->assertNull($extra['codeRun']['exitCode']);
		$this->assertSame(['wallSeconds' => null, 'memoryMb' => 256], $extra['codeRun']['limits']);
		$this->assertSame('', $extra['codeRun']['stdout']);

		$this->assertSame('', CodeRunTrace::extra(arguments: [], result: 'not an array', redaction: null)['codeRun']['code']);
	}//end testARefusedCallRecordsItsErrorCode()
}//end class
