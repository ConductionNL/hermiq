<?php

/**
 * A run-scoped computer pre-authorisation widens only an approved computer action
 * that names its run, machine, agent and reviewer, lasts fifteen minutes, and is
 * live only while approved, decided and unexpired.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tools-browser-and-computer-use/specs/computer-use/spec.md#requirement-every-computer-action-passes-the-approval-gate-req-computer-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\ComputerUse;

use DateTimeImmutable;
use OCA\Hermiq\Mcp\ComputerToolDescriptors;
use OCA\Hermiq\Service\ComputerUse\ComputerRunGrant;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\ComputerUse\ComputerRunGrant
 */
final class ComputerRunGrantTest extends TestCase {

	/**
	 * An approved click approval of run-1 on machine m-1.
	 *
	 * @return array<string, mixed>
	 */
	private static function approvedClick(): array {
		return [
			'status' => 'approved',
			'sourceType' => 'toolcall',
			'toolId' => ComputerToolDescriptors::CLICK,
			'toolArguments' => ['target' => 'm-1', 'x' => 1, 'y' => 2],
			'runId' => 'run-1',
			'agentId' => 'agent-1',
			'reviewer' => 'alice',
			'reviewerType' => 'user',
		];
	}

	public function testTheRecordWidensTheMachineForFifteenMinutesInUtc(): void {
		$now = new DateTimeImmutable('2026-11-17T12:00:00+01:00');

		$record = ComputerRunGrant::recordFor(data: self::approvedClick(), deciderUid: 'bob', now: $now);

		self::assertSame(
			[
				'status' => 'approved',
				'sourceType' => 'computer-run',
				'correlationId' => ComputerRunGrant::key(agentId: 'agent-1', runId: 'run-1', target: 'm-1'),
				'agentId' => 'agent-1',
				'toolId' => ComputerToolDescriptors::CLICK,
				'toolArguments' => ['target' => 'm-1'],
				'runId' => 'run-1',
				'prompt' => 'Actions on this machine for the rest of this run, for at most 15 minutes.',
				'requestedAt' => '2026-11-17T11:00:00+00:00',
				'reviewer' => 'alice',
				'reviewerType' => 'user',
				'decidedAt' => '2026-11-17T11:00:00+00:00',
				'decidedBy' => 'bob',
				'expiresAt' => '2026-11-17T11:15:00+00:00',
			],
			$record
		);
	}

	public function testOnlyAnApprovedComputerToolcallWithRunMachineAgentAndReviewerIsWidened(): void {
		$now = new DateTimeImmutable('2026-11-17T12:00:00Z');
		$cases = [
			'pending' => ['status' => 'pending'],
			'other source' => ['sourceType' => 'workspace-run'],
			'not a computer tool' => ['toolId' => 'hermiq.browserOpen'],
			'no run' => ['runId' => ''],
			'no machine' => ['toolArguments' => []],
			'no agent' => ['agentId' => ''],
		];
		foreach ($cases as $why => $change) {
			self::assertNull(
				ComputerRunGrant::recordFor(data: array_merge(self::approvedClick(), $change), deciderUid: 'bob', now: $now),
				$why
			);
		}

		self::assertNull(ComputerRunGrant::recordFor(data: self::approvedClick(), deciderUid: '', now: $now), 'no reviewer');
	}

	public function testTheKeyIsPerAgentRunAndMachine(): void {
		$key = ComputerRunGrant::key(agentId: 'a', runId: 'r', target: 't');

		self::assertSame(hash('sha256', 'computer-run|a|r|t'), $key);
		self::assertNotSame($key, ComputerRunGrant::key(agentId: 'a', runId: 'r2', target: 't'));
		self::assertNotSame($key, ComputerRunGrant::key(agentId: 'a', runId: 'r', target: 't2'));
	}

	public function testAGrantIsLiveOnlyWhileApprovedDecidedAndUnexpired(): void {
		$now = new DateTimeImmutable('2026-11-17T11:10:00Z');
		$live = ['status' => 'approved', 'decidedBy' => 'bob', 'expiresAt' => '2026-11-17T11:15:00+00:00'];

		self::assertTrue(ComputerRunGrant::isLive(data: $live, now: $now));
		self::assertFalse(ComputerRunGrant::isLive(data: $live, now: new DateTimeImmutable('2026-11-17T11:15:00Z')), 'at its end');
		self::assertFalse(ComputerRunGrant::isLive(data: array_merge($live, ['status' => 'rejected']), now: $now), 'rejected');
		self::assertFalse(ComputerRunGrant::isLive(data: array_merge($live, ['decidedBy' => '']), now: $now), 'undecided');
		self::assertFalse(ComputerRunGrant::isLive(data: array_merge($live, ['expiresAt' => '']), now: $now), 'no end');
		self::assertFalse(ComputerRunGrant::isLive(data: array_merge($live, ['expiresAt' => 'not a moment']), now: $now), 'unreadable end');
	}
}
