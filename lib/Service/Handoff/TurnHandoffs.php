<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Service
 * @package   OCA\Hermiq\Service\Handoff
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/hermiq
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Handoff;

use OCA\Hermiq\Service\RemoteAgent\RemoteTasks;

/**
 * The one place a tool result inside a turn becomes a handoff.
 *
 * FacadeToolInvoker hands every successful tool result here with the turn's
 * agent, conversation and owner, which only the server knows; the model never
 * names them. A run queued by `openregister.runFlow` becomes a flow handoff, a
 * task still working on a remote agent an a2a handoff. Every other result
 * passes through unchanged.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
class TurnHandoffs {

	/**
	 * The tool that asks a remote agent.
	 */
	public const ASK_REMOTE_AGENT = 'hermiq.askRemoteAgent';

	/**
	 * The tool that queues a flow run.
	 */
	public const RUN_FLOW = 'openregister.runFlow';

	/**
	 * Constructor.
	 *
	 * @param FlowHandoffs $flows       Flow handoffs.
	 * @param RemoteTasks  $remoteTasks Remote agent tasks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function __construct(
		private readonly FlowHandoffs $flows,
		private readonly RemoteTasks $remoteTasks,
	) {
	}//end __construct()

	/**
	 * The tool result, with a handoff opened when the tool started work it did not wait for.
	 *
	 * @param string               $toolId    The registry tool id.
	 * @param array<string, mixed> $arguments The call's arguments.
	 * @param mixed                $result    The tool's result.
	 * @param FlowTurn             $turn      The turn.
	 *
	 * @return mixed
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function afterToolCall(string $toolId, array $arguments, mixed $result, FlowTurn $turn): mixed {
		return match ($toolId) {
			self::RUN_FLOW => $this->flows->afterRunFlow(arguments: $arguments, result: $result, turn: $turn),
			self::ASK_REMOTE_AGENT => $this->remoteTasks->afterAsk(result: $result, turn: $turn),
			default => $result,
		};
	}//end afterToolCall()
}//end class
