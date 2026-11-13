<?php

/**
 * Unit tests for ToolCallReporter.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/run-audit-log/spec.md#requirement-every-run-and-tool-call-is-audited-mvp
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolCallReporter;
use OCA\Hermiq\Service\Engine\ToolDispatch;
use PHPUnit\Framework\TestCase;

/**
 * A dispatched tool call is streamed and traced from start to finish.
 *
 * @covers \OCA\Hermiq\Service\Engine\ToolCallReporter
 * @uses   \OCA\Hermiq\Service\Engine\ToolDispatch
 */
final class ToolCallReporterTest extends TestCase {
	public function testStartAndFinishAreStreamedAndTraced(): void {
		$dispatch = new ToolDispatch('search', ['q' => 'x']);
		$trace = $this->createMock(RunTraceCollector::class);
		$channel = $this->createMock(StreamYieldChannel::class);
		$trace->expects($this->once())->method('startStep')->with('tool', 'search')->willReturn(4);
		$trace->expects($this->once())->method('endStep')->with(4, 'ok', ['hits' => 2]);
		$channel->expects($this->once())->method('emitToolCall')->with(['toolId' => 'search', 'arguments' => ['q' => 'x']]);
		$channel->expects($this->once())->method('emitToolResult')
			->with(['toolId' => 'search', 'result' => ['n' => 2], 'isError' => false]);
		$reporter = new ToolCallReporter($channel, $trace);

		$reporter->started($dispatch);
		$this->assertSame(4, $dispatch->traceToken);
		$dispatch->envelope = ['result' => ['n' => 2], 'isError' => false];
		$reporter->finished($dispatch, ['hits' => 2]);
	}

	public function testAnErroredCallIsTracedAsError(): void {
		$dispatch = new ToolDispatch('search', []);
		$dispatch->traceToken = 9;
		$dispatch->envelope = ['result' => 'boom', 'isError' => true];
		$trace = $this->createMock(RunTraceCollector::class);
		$trace->expects($this->once())->method('endStep')->with(9, 'error', []);

		(new ToolCallReporter(null, $trace))->finished($dispatch, []);
	}

	public function testWithoutATokenTheStepIsNotEnded(): void {
		$dispatch = new ToolDispatch('search', []);
		$dispatch->envelope = ['result' => null, 'isError' => false];
		$trace = $this->createMock(RunTraceCollector::class);
		$trace->expects($this->never())->method('endStep');

		(new ToolCallReporter(null, $trace))->finished($dispatch, []);
	}

	public function testWithoutTraceTheTokenStaysEmpty(): void {
		$dispatch = new ToolDispatch('search', []);
		$dispatch->traceToken = 5;

		(new ToolCallReporter(null, null))->started($dispatch);

		$this->assertNull($dispatch->traceToken);
	}
}
