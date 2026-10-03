<?php

/**
 * Register tests for agents-switch-off-and-stop: the cascade that removes an
 * agent's schedules, the availability and cap fields, and the switched-off
 * seed agent, each read from the real register files.
 *
 * @category Test
 * @package  OCA\Hermiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
 */

declare(strict_types=1);

namespace OCA\Hermiq\Tests\Unit\Settings;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the register declarations of the agent switch.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-deleting-an-agent-removes-its-schedules-req-agoff-004
 */
class AgentSwitchSchemaTest extends TestCase {

	/**
	 * Decode a register file under lib/Settings.
	 *
	 * @param string $file The file name.
	 *
	 * @return object
	 */
	private function register(string $file): object {
		return json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/' . $file));

	}//end register()

	/**
	 * Deleting an agent deletes its schedules and its standing goals:
	 * `onDelete: CASCADE` on Schedule.agentId and Goal.agentId
	 * (agents-standing-goal D1), and on nothing else that points at an agent.
	 *
	 * @return void
	 */
	public function testScheduleAgentIdCascades(): void {
		$schemas = $this->register(file: 'hermiq_register.json')->components->schemas;

		$this->assertSame('agent', $schemas->Schedule->properties->agentId->{'$ref'});
		$this->assertSame('CASCADE', $schemas->Schedule->properties->agentId->onDelete ?? null);
		$this->assertSame('agent', $schemas->Goal->properties->agentId->{'$ref'});
		$this->assertSame('CASCADE', $schemas->Goal->properties->agentId->onDelete ?? null);

		foreach ((array)$schemas as $name => $schema) {
			if ($name === 'Schedule' || $name === 'Goal') {
				continue;
			}

			foreach ((array)($schema->properties ?? []) as $property => $definition) {
				if (($definition->{'$ref'} ?? null) === 'agent') {
					$this->assertNotSame('CASCADE', $definition->onDelete ?? null, "{$name}.{$property} keeps its history when an agent is deleted.");
				}
			}
		}

	}//end testScheduleAgentIdCascades()

	/**
	 * The Agent fragment declares the cap and who switched the agent, when and why.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function testAgentDeclaresTheCapAndTheAvailabilityFields(): void {
		$agent = $this->register(file: 'hermiq_register.json')->components->schemas->Agent;

		$cap = $agent->properties->maxToolCalls;
		$this->assertSame('integer', $cap->type);
		$this->assertSame(10, $cap->default);
		$this->assertSame(1, $cap->minimum);
		$this->assertSame(100, $cap->maximum);

		foreach (['availabilityChangedBy', 'availabilityChangedAt', 'availabilityReason'] as $field) {
			$this->assertObjectHasProperty($field, $agent->properties);
		}

	}//end testAgentDeclaresTheCapAndTheAvailabilityFields()

	/**
	 * A fresh install shows the switched-off state: "Weekly supplier digest" is
	 * seeded off with its reason, and the seed validates against the fragment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-an-agent-can-be-switched-off-and-on-without-deleting-it-req-agoff-001
	 */
	public function testTheSwitchedOffSeedAgentValidates(): void {
		$agentSchema = $this->register(file: 'hermiq_register.json')->components->schemas->Agent;
		$objects = $this->register(file: 'hermiq_mock_register.json')->components->objects;

		$digest = null;
		foreach ($objects as $object) {
			if ($object->{'@self'}->schema === 'agent' && ($object->name ?? '') === 'Weekly supplier digest') {
				$digest = $object;
			}
		}

		$this->assertNotNull($digest, 'The switched-off example agent is seeded.');
		$this->assertFalse($digest->active);
		$this->assertSame('Paused until the new supplier list is approved', $digest->availabilityReason);

		$payload = clone $digest;
		unset($payload->{'@self'});
		$result = (new Validator())->validate($payload, $agentSchema);
		$this->assertTrue($result->isValid(), 'The seed agent must validate against the Agent fragment.');

	}//end testTheSwitchedOffSeedAgentValidates()
}//end class
