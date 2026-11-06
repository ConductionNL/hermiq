<?php

/**
 * Hermiq: whether an agent may run, and how many tool calls a turn may make.
 *
 * A pure rule over the agent record (agents-switch-off-and-stop): an agent is
 * on unless its `active` is exactly false, and its tool call cap is
 * `maxToolCalls` kept within 1 to 100, 10 when unset.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Agent
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Agent;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The availability rule and the tool call cap of one agent.
 *
 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
 */
class AgentAvailability {

	/**
	 * The cap when the agent sets none.
	 */
	public const DEFAULT_MAX_TOOL_CALLS = 10;

	/**
	 * The lowest cap an owner may set.
	 */
	public const MIN_TOOL_CALLS = 1;

	/**
	 * The highest cap an owner may set.
	 */
	public const MAX_TOOL_CALLS = 100;

	/**
	 * Whether the agent is on. No agent (agent-less chat) is on.
	 *
	 * @param ObjectEntity|null $agent The agent.
	 *
	 * @return bool True unless `active` is exactly false.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
	 */
	public function isOn(?ObjectEntity $agent): bool {
		if ($agent === null) {
			return true;
		}

		return ($agent->getObject()['active'] ?? true) !== false;

	}//end isOn()

	/**
	 * Refuse a switched-off agent.
	 *
	 * @param ObjectEntity|null $agent The agent.
	 *
	 * @return void
	 *
	 * @throws AgentSwitchedOffException When the agent is switched off.
	 *
	 * @spec openspec/specs/agent-management-ui/spec.md#requirement-a-switched-off-agent-does-not-run-on-any-path-req-agoff-002
	 */
	public function assertRunnable(?ObjectEntity $agent): void {
		if ($this->isOn(agent: $agent) === false) {
			throw new AgentSwitchedOffException();
		}

	}//end assertRunnable()

	/**
	 * The tool calls one turn of this agent may make.
	 *
	 * @param ObjectEntity|null $agent The agent.
	 *
	 * @return int The cap, from 1 to 100.
	 *
	 * @spec openspec/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function maxToolCalls(?ObjectEntity $agent): int {
		$value = $agent?->getObject()['maxToolCalls'] ?? null;
		if (is_int($value) === false) {
			return self::DEFAULT_MAX_TOOL_CALLS;
		}

		return max(self::MIN_TOOL_CALLS, min(self::MAX_TOOL_CALLS, $value));

	}//end maxToolCalls()

}//end class
