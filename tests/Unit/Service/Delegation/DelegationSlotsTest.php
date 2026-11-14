<?php

/**
 * Hermiq DelegationSlots tests: the slots keep call order, count the parts
 * that passed, and run only those.
 *
 * @category Tests
 * @package  OCA\Hermiq\Tests\Unit\Service\Delegation
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
 * @spec openspec/changes/delegation-runs-in-parallel/specs/sub-agent-delegation/spec.md#requirement-several-parts-of-one-task-run-on-sub-agents-at-the-same-time-req-dlgpar-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Delegation;

use OCA\Hermiq\Service\Delegation\DelegatedTurn;
use OCA\Hermiq\Service\Delegation\DelegationSlots;
use OCA\Hermiq\Service\Delegation\PassedDelegation;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Delegation\DelegationSlots
 * @uses   \OCA\Hermiq\Service\Delegation\PassedDelegation
 * @uses   \OCA\Hermiq\Service\Delegation\DelegatedTurn
 */
final class DelegationSlotsTest extends TestCase {

	/**
	 * A part that passed its gates, for an agent with this uuid.
	 *
	 * @param string $uuid The agent's uuid.
	 * @param string $task The task.
	 *
	 * @return PassedDelegation
	 */
	private function passed(string $uuid, string $task): PassedDelegation {
		$agent = $this->createMock(ObjectEntity::class);
		$agent->method('getUuid')->willReturn($uuid);
		return new PassedDelegation(targetAgent: $agent, turn: new DelegatedTurn(task: $task, organisation: 'org-x', callerFrame: null));
	}//end passed()

	/**
	 * Only passed parts are counted and run; every slot keeps its place.
	 *
	 * @return void
	 */
	public function testRunsThePassedPartsAndKeepsCallOrder(): void {
		$slots = new DelegationSlots();
		$this->assertSame(0, $slots->passed());

		$slots->add(slot: $this->passed(uuid: 'agent-b', task: 'one'));
		$slots->add(slot: ['targetAgentId' => 'agent-d', 'error' => ['code' => 'delegation_not_allowed']]);
		$slots->add(slot: $this->passed(uuid: 'agent-c', task: 'three'));
		$this->assertSame(2, $slots->passed());

		$ran = [];
		$results = $slots->results(
			run: static function (PassedDelegation $part) use (&$ran): array {
				$ran[] = $part->turn->task;
				return ['result' => 'did '.$part->turn->task];
			}
		);

		$this->assertSame(['one', 'three'], $ran);
		$this->assertSame(
			[
				['targetAgentId' => 'agent-b', 'result' => 'did one'],
				['targetAgentId' => 'agent-d', 'error' => ['code' => 'delegation_not_allowed']],
				['targetAgentId' => 'agent-c', 'result' => 'did three'],
			],
			$results
		);
	}//end testRunsThePassedPartsAndKeepsCallOrder()

	/**
	 * A failed run keeps the agent's name on its error slot.
	 *
	 * @return void
	 */
	public function testAFailedRunIsNamed(): void {
		$slots = new DelegationSlots();
		$slots->add(slot: $this->passed(uuid: 'agent-b', task: 'one'));

		$results = $slots->results(run: static fn (): array => ['error' => ['code' => 'delegation_failed']]);

		$this->assertSame([['targetAgentId' => 'agent-b', 'error' => ['code' => 'delegation_failed']]], $results);
	}//end testAFailedRunIsNamed()
}//end class
