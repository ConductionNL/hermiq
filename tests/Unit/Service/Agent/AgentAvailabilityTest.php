<?php

/**
 * Unit tests for the one availability rule every run path asks.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Agent;

use OCA\Hermiq\Service\Agent\AgentAvailability;
use OCA\Hermiq\Service\Agent\AgentSwitchedOffException;
use OCA\OpenRegister\Db\ObjectEntity;
use PHPUnit\Framework\TestCase;

/**
 * Tests AgentAvailability.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */
class AgentAvailabilityTest extends TestCase {

	/**
	 * Build a real agent object with the given payload.
	 *
	 * @param array<string, mixed> $data The agent payload.
	 *
	 * @return ObjectEntity
	 */
	private function agent(array $data): ObjectEntity {
		$agent = new ObjectEntity();
		$agent->setUuid('agent-1');
		$agent->setObject($data);
		return $agent;

	}//end agent()

	/**
	 * An agent is on unless `active` is exactly false; no agent (agent-less chat) is on.
	 *
	 * @return void
	 */
	public function testOnUnlessActiveIsFalse(): void {
		$rule = new AgentAvailability();

		$this->assertTrue($rule->isOn(agent: null));
		$this->assertTrue($rule->isOn(agent: $this->agent(data: ['name' => 'A'])));
		$this->assertTrue($rule->isOn(agent: $this->agent(data: ['active' => true])));
		$this->assertFalse($rule->isOn(agent: $this->agent(data: ['active' => false])));

	}//end testOnUnlessActiveIsFalse()

	/**
	 * A switched-off agent is refused with HTTP 409 and the sentence the chat shows.
	 *
	 * @return void
	 */
	public function testSwitchedOffAgentIsRefusedWith409(): void {
		$rule = new AgentAvailability();
		$rule->assertRunnable(agent: $this->agent(data: ['active' => true]));

		try {
			$rule->assertRunnable(agent: $this->agent(data: ['active' => false]));
			$this->fail('A switched-off agent must be refused.');
		} catch (AgentSwitchedOffException $e) {
			$this->assertSame(409, $e->getCode());
			$this->assertSame('This agent is switched off.', $e->getMessage());
			$this->assertSame('agent_switched_off', AgentSwitchedOffException::ERROR_CODE);
		}

	}//end testSwitchedOffAgentIsRefusedWith409()

	/**
	 * The tool call cap defaults to 10 and stays within 1 to 100.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function testMaxToolCallsDefaultsAndClamps(): void {
		$rule = new AgentAvailability();

		$this->assertSame(10, $rule->maxToolCalls(agent: null));
		$this->assertSame(10, $rule->maxToolCalls(agent: $this->agent(data: [])));
		$this->assertSame(25, $rule->maxToolCalls(agent: $this->agent(data: ['maxToolCalls' => 25])));
		$this->assertSame(1, $rule->maxToolCalls(agent: $this->agent(data: ['maxToolCalls' => 0])));
		$this->assertSame(100, $rule->maxToolCalls(agent: $this->agent(data: ['maxToolCalls' => 500])));
		$this->assertSame(10, $rule->maxToolCalls(agent: $this->agent(data: ['maxToolCalls' => 'many'])));

	}//end testMaxToolCallsDefaultsAndClamps()
}//end class
