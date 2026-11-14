<?php

/**
 * Hermiq DelegationPart tests: a part reads its agent and task trimmed, and
 * anything the model sent that is not text reads as absent.
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

use OCA\Hermiq\Service\Delegation\DelegationPart;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Hermiq\Service\Delegation\DelegationPart
 */
final class DelegationPartTest extends TestCase {

	/**
	 * A well-formed part is read trimmed and is complete.
	 *
	 * @return void
	 */
	public function testAWellFormedPartIsReadTrimmed(): void {
		$part = new DelegationPart(part: ['targetAgentId' => ' agent-b ', 'task' => ' summarise ', 'extra' => 1]);

		$this->assertSame('agent-b', $part->targetAgentId);
		$this->assertSame('summarise', $part->task);
		$this->assertTrue($part->isComplete());
	}//end testAWellFormedPartIsReadTrimmed()

	/**
	 * @return array<string, array{mixed, string, string}>
	 */
	public static function malformedParts(): array {
		return [
			'a string' => ['agent-b', '', ''],
			'no task' => [['targetAgentId' => 'agent-b'], 'agent-b', ''],
			'blank task' => [['targetAgentId' => 'agent-b', 'task' => "\t "], 'agent-b', ''],
			'a list as agent' => [['targetAgentId' => ['agent-b'], 'task' => 'x'], '', 'x'],
			'an object as task' => [['targetAgentId' => 'agent-b', 'task' => new \stdClass()], 'agent-b', ''],
			'null' => [null, '', ''],
		];
	}//end malformedParts()

	/**
	 * Anything that is not text reads as absent, and the part is incomplete.
	 *
	 * @param mixed  $raw    What the model sent.
	 * @param string $agent  The agent read.
	 * @param string $task   The task read.
	 *
	 * @return void
	 *
	 * @dataProvider malformedParts
	 */
	public function testAMalformedPartIsIncomplete(mixed $raw, string $agent, string $task): void {
		$part = new DelegationPart(part: $raw);

		$this->assertSame($agent, $part->targetAgentId);
		$this->assertSame($task, $part->task);
		$this->assertFalse($part->isComplete());
	}//end testAMalformedPartIsIncomplete()

	/**
	 * A number is text: an agent id or a task may arrive as one.
	 *
	 * @return void
	 */
	public function testANumberReadsAsText(): void {
		$part = new DelegationPart(part: ['targetAgentId' => 42, 'task' => 7]);

		$this->assertSame('42', $part->targetAgentId);
		$this->assertTrue($part->isComplete());
	}//end testANumberReadsAsText()

	/**
	 * A slot names the agent first and keeps the outcome as it is.
	 *
	 * @return void
	 */
	public function testASlotNamesItsAgent(): void {
		$slot = (new DelegationPart(part: ['targetAgentId' => 'agent-d', 'task' => 'x']))->slot(outcome: ['error' => ['code' => 'delegation_not_allowed']]);

		$this->assertSame(['targetAgentId' => 'agent-d', 'error' => ['code' => 'delegation_not_allowed']], $slot);
	}//end testASlotNamesItsAgent()
}//end class
