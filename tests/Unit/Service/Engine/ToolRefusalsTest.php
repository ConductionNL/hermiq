<?php

/**
 * Unit tests for ToolRefusals.
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
 * @spec openspec/specs/agent-guardrails/spec.md#requirement-every-guardrail-action-is-visible-in-run-history
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\ConfirmRefusal;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\StreamYieldChannel;
use OCA\Hermiq\Service\Engine\ToolCall;
use OCA\Hermiq\Service\Engine\ToolRefusal;
use OCA\Hermiq\Service\Engine\ToolRefusals;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A refused tool call is traced, streamed and answered as an error envelope.
 *
 * @covers \OCA\Hermiq\Service\Engine\ToolRefusals
 * @uses   \OCA\Hermiq\Service\Engine\ConfirmRefusal
 * @uses   \OCA\Hermiq\Service\Engine\ToolCall
 * @uses   \OCA\Hermiq\Service\Engine\ToolRefusal
 */
final class ToolRefusalsTest extends TestCase {
	private RunTraceCollector&MockObject $trace;

	private StreamYieldChannel&MockObject $channel;

	protected function setUp(): void {
		$this->trace   = $this->createMock(RunTraceCollector::class);
		$this->channel = $this->createMock(StreamYieldChannel::class);
	}

	private function approval(string $uuid): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		return $entity;
	}

	public function testARefusalIsTracedStreamedAndEncoded(): void {
		$call = new ToolCall('files.delete', ['path' => '/a']);
		$refusal = new ToolRefusal($call, ['isError' => true, 'error' => 'blocked']);
		$refusal->outcome = 'blocked';
		$refusal->traceExtra = ['reason' => 'policy'];
		$refusal->traceName = 'files.delete (guarded)';
		$this->trace->expects($this->once())->method('startStep')->with('tool', 'files.delete (guarded)')->willReturn(7);
		$this->trace->expects($this->once())->method('endStep')->with(7, 'blocked', ['reason' => 'policy']);
		$this->channel->expects($this->once())->method('emitToolCall')
			->with(['toolId' => 'files.delete', 'arguments' => ['path' => '/a']]);
		$this->channel->expects($this->once())->method('emitToolResult')
			->with(['toolId' => 'files.delete', 'result' => ['isError' => true, 'error' => 'blocked'], 'isError' => true]);

		$encoded = (new ToolRefusals($this->trace, $this->channel))->refuse($refusal);

		$this->assertSame('{"isError":true,"error":"blocked"}', $encoded);
	}

	public function testTheTraceNameDefaultsToTheToolId(): void {
		$this->trace->expects($this->once())->method('startStep')->with('tool', 'search')->willReturn(1);
		$this->trace->expects($this->once())->method('endStep')->with(1, 'refused', []);

		(new ToolRefusals($this->trace, null))->refuse(new ToolRefusal(new ToolCall('search', []), ['isError' => true]));
	}

	public function testWithoutTraceOrChannelOnlyTheEnvelopeIsReturned(): void {
		$this->assertSame(
			'{"isError":true}',
			(new ToolRefusals(null, null))->refuse(new ToolRefusal(new ToolCall('x', []), ['isError' => true]))
		);
	}

	public function testConfirmBuildsAnApprovalRequiredEnvelope(): void {
		$confirm = new ConfirmRefusal('pending', 'Needs a reviewer');
		$confirm->approvalId = 'ap-1';
		$this->trace->method('startStep')->willReturn(2);
		$this->trace->expects($this->once())->method('endStep')->with(2, 'awaiting_approval', []);

		$encoded = (new ToolRefusals($this->trace, null))->confirm(new ToolCall('mail.send', ['to' => 'x']), $confirm);

		$this->assertSame(
			[
				'isError' => true,
				'error' => 'approval_required',
				'toolId' => 'mail.send',
				'status' => 'pending',
				'message' => 'Needs a reviewer',
				'approvalId' => 'ap-1',
			],
			json_decode($encoded, true)
		);
	}

	public function testConfirmLeavesOutAMissingApprovalId(): void {
		$encoded = (new ToolRefusals(null, null))->confirm(new ToolCall('t', []), new ConfirmRefusal('pending', 'm'));

		$this->assertArrayNotHasKey('approvalId', json_decode($encoded, true));
	}

	public function testUnavailableIsADeniedConfirmation(): void {
		$this->trace->method('startStep')->willReturn(3);
		$this->trace->expects($this->once())->method('endStep')->with(3, 'denied', []);

		$envelope = json_decode((new ToolRefusals($this->trace, null))->unavailable(new ToolCall('t', [])), true);

		$this->assertSame('unavailable', $envelope['status']);
		$this->assertSame('approval_required', $envelope['error']);
		$this->assertStringContainsString('no agent context is available', $envelope['message']);
		$this->assertArrayNotHasKey('approvalId', $envelope);
	}

	public function testAwaitingCarriesTheApprovalUuid(): void {
		$confirm = (new ToolRefusals(null, null))->awaiting('Wait', $this->approval('ap-9'));

		$this->assertSame('pending', $confirm->status);
		$this->assertSame('Wait', $confirm->message);
		$this->assertSame('ap-9', $confirm->approvalId);
		$this->assertSame('awaiting_approval', $confirm->outcome);
	}

	public function testGateVerdictPrefersADecidedDenial(): void {
		$this->assertSame(
			[
				'status' => 'denied',
				'approvalId' => 'd-1',
				'message' => 'This action was denied by a reviewer and cannot be run.',
			],
			(new ToolRefusals(null, null))->gateVerdict($this->approval('d-1'), $this->approval('p-1'))
		);
	}

	public function testGateVerdictOtherwiseIsPending(): void {
		$refusals = new ToolRefusals(null, null);

		$this->assertSame(
			['status' => 'pending', 'approvalId' => 'p-1', 'message' => 'This action requires human approval before it can run.'],
			$refusals->gateVerdict(null, $this->approval('p-1'))
		);
		$this->assertSame('', $refusals->gateVerdict(null, null)['approvalId']);
	}

	public function testAnUnencodableEnvelopeKeepsOnlyItsErrorKeys(): void {
		$encoded = (new ToolRefusals(null, null))->encoded(
			['ok' => false, 'isError' => true, 'error' => 'x', 'detail' => "\xB1\x31"]
		);

		$this->assertSame('{"ok":false,"isError":true,"error":"x"}', $encoded);
	}
}
