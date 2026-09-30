<?php

/**
 * Unit tests for the two ways a turn stops inside the tool loop: the owner's
 * tool call cap, and the agent being switched off while it runs.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Service\Engine;

use OCA\Hermiq\Service\Engine\FacadeToolInvoker;
use OCA\Hermiq\Service\Engine\RunTraceCollector;
use OCA\Hermiq\Service\Engine\TurnStoppedException;
use OCA\OpenRegister\Service\Mcp\ToolRegistryFacade;
use PHPUnit\Framework\TestCase;

/**
 * Tests the stop conditions of FacadeToolInvoker.
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */
class FacadeToolInvokerStopTest extends TestCase {

	/**
	 * A facade that answers every call and counts them.
	 *
	 * @param int $calls Incremented on every invokeTool().
	 *
	 * @return ToolRegistryFacade
	 */
	private function countingFacade(int &$calls): ToolRegistryFacade {
		$facade = $this->createMock(ToolRegistryFacade::class);
		$facade->method('invokeTool')->willReturnCallback(
			static function () use (&$calls): array {
				$calls++;
				return ['result' => ['hits' => []], 'isError' => false];
			}
		);
		return $facade;

	}//end countingFacade()

	/**
	 * With maxToolCalls 5 the sixth call is not invoked, the model is told the
	 * limit is reached, the trace says so, and a further call ends the turn.
	 *
	 * @return void
	 */
	public function testSixthCallPastACapOfFiveIsNotInvoked(): void {
		$calls = 0;
		$trace = new RunTraceCollector();
		$invoker = new FacadeToolInvoker(facade: $this->countingFacade(calls: $calls), trace: $trace, maxToolCalls: 5);

		for ($i = 0; $i < 5; $i++) {
			$invoker->search_objects(query: 'permit');
		}

		$sixth = json_decode($invoker->search_objects(query: 'permit'), true);

		$this->assertSame(5, $calls, 'The sixth tool call must not reach the facade.');
		$this->assertTrue($sixth['isError']);
		$this->assertStringContainsString('Tool call limit reached for this turn', (string)$sixth['result']['error']);

		$names = array_column($trace->toArray(), 'name');
		$this->assertContains('Tool call limit reached for this turn', $names);

		$this->expectException(TurnStoppedException::class);
		$invoker->search_objects(query: 'permit');

	}//end testSixthCallPastACapOfFiveIsNotInvoked()

	/**
	 * An agent switched off after its first call: the next call is not made and
	 * the trace records "Stopped: agent switched off".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	public function testAgentSwitchedOffMidTurnStopsAtTheNextCall(): void {
		$calls = 0;
		$on = true;
		$trace = new RunTraceCollector();
		$invoker = new FacadeToolInvoker(
			facade: $this->countingFacade(calls: $calls),
			trace: $trace,
			agentStillOn: static function () use (&$on): bool {
				return $on;
			}
		);

		$invoker->search_objects(query: 'a');
		$on = false;
		$second = json_decode($invoker->search_objects(query: 'b'), true);

		$this->assertSame(1, $calls, 'No tool call may be made after the agent is switched off.');
		$this->assertTrue($second['isError']);
		$this->assertContains('Stopped: agent switched off', array_column($trace->toArray(), 'name'));
		$this->assertTrue($invoker->isStopped());

	}//end testAgentSwitchedOffMidTurnStopsAtTheNextCall()

	/**
	 * Without a cap or a switch (every pre-existing caller) nothing changes.
	 *
	 * @return void
	 */
	public function testNoCapNoSwitchIsUnchanged(): void {
		$calls = 0;
		$invoker = new FacadeToolInvoker(facade: $this->countingFacade(calls: $calls));

		for ($i = 0; $i < 30; $i++) {
			$invoker->search_objects(query: 'x');
		}

		$this->assertSame(30, $calls);
		$this->assertFalse($invoker->isStopped());

	}//end testNoCapNoSwitchIsUnchanged()
}//end class
