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

/**
 * The turn a handoff is opened from: its agent, its conversation and the owner the late result is delivered to.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
 */
final class FlowTurn {
	/**
	 * Constructor.
	 *
	 * @param string $agentId        The acting agent.
	 * @param string $conversationId The conversation the turn belongs to.
	 * @param string $owner          The owning user the run acts as.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly string $conversationId,
		public readonly string $owner,
	) {
	}//end __construct()

	/**
	 * Whether a late result could be delivered to this turn: it has an agent, a conversation and an owner.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/agent-handoffs/spec.md#requirement-work-an-agent-starts-and-waits-for-is-recorded-as-a-handoff-req-handoff-001
	 */
	public function isComplete(): bool {
		return $this->agentId !== '' && $this->conversationId !== '' && $this->owner !== '';
	}//end isComplete()
}//end class
