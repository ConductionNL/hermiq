<?php

/**
 * Hermiq: stop a turn at its next tool call (agents-switch-off-and-stop).
 *
 * One guard per turn, held by the tool invoker. Before each tool call it asks
 * whether the agent is still switched on and whether the turn has tool calls
 * left. The first refusal stops the turn: that call gets an error result, and
 * any later call throws TurnStoppedException so the turn ends.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\Engine
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Engine;

use Closure;

/**
 * The per-turn stop rule of the tool loop.
 *
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
 */
class TurnGuard {

	/**
	 * The trace step and error when the agent was switched off during the turn.
	 */
	public const SWITCHED_OFF = 'Stopped: agent switched off';

	/**
	 * The trace step and error when the turn used all its tool calls.
	 */
	public const LIMIT_REACHED = 'Tool call limit reached for this turn';

	/**
	 * Tool calls let through so far.
	 *
	 * @var integer
	 */
	private int $calls = 0;

	/**
	 * Why the turn stopped, or null while it runs.
	 *
	 * @var string|null
	 */
	private ?string $stopReason = null;

	/**
	 * Constructor.
	 *
	 * @param integer|null $maxToolCalls  Tool calls one turn may make; null is no cap.
	 * @param Closure|null $agentStillOn  Fresh read of the agent's switch; null is always on.
	 */
	public function __construct(
		private readonly ?int $maxToolCalls = null,
		private readonly ?Closure $agentStillOn = null,
	) {
	}//end __construct()

	/**
	 * Admit one tool call, or say why not.
	 *
	 * @return string|null Null to run the call; the stop reason when this call is refused.
	 *
	 * @throws TurnStoppedException When the turn was already stopped.
	 *
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-tool-governance/spec.md#requirement-an-agent-stops-after-the-tool-calls-its-owner-allows-req-agoff-005
	 */
	public function admit(): ?string {
		if ($this->stopReason !== null) {
			throw new TurnStoppedException($this->stopReason);
		}

		if ($this->agentStillOn !== null && ($this->agentStillOn)() === false) {
			$this->stopReason = self::SWITCHED_OFF;
			return $this->stopReason;
		}

		if ($this->maxToolCalls !== null && $this->calls >= $this->maxToolCalls) {
			$this->stopReason = self::LIMIT_REACHED;
			return $this->stopReason;
		}

		$this->calls++;
		return null;
	}//end admit()

	/**
	 * Whether the turn was stopped.
	 *
	 * @return bool True once a call was refused.
	 *
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	public function isStopped(): bool {
		return $this->stopReason !== null;
	}//end isStopped()

	/**
	 * Why the turn stopped, or null.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/agents-switch-off-and-stop/specs/agent-management-ui/spec.md#requirement-a-run-in-progress-stops-when-its-agent-is-switched-off-req-agoff-003
	 */
	public function stopReason(): ?string {
		return $this->stopReason;
	}//end stopReason()

}//end class
