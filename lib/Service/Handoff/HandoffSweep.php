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
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\Handoff;

use OCA\Hermiq\Service\RemoteAgent\RemoteTasks;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One pass over the waiting handoffs: expire every one past its deadline, and
 * ask each remaining a2a task once through its remote agent's source.
 *
 * Expiry is here because OpenRegister offers no timed lifecycle transition yet
 * (design, declarative versus imperative); flow and webhook handoffs that
 * never hear back end as `expired` on the first pass after their deadline.
 *
 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
 */
class HandoffSweep {

	/**
	 * Constructor.
	 *
	 * @param HandoffService  $handoffs    The handoff record.
	 * @param RemoteTasks     $remoteTasks Asks a remote task's state.
	 * @param LoggerInterface $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function __construct(
		private readonly HandoffService $handoffs,
		private readonly RemoteTasks $remoteTasks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Run one pass.
	 *
	 * @return array<string, int> How many handoffs ended as `expired`, `done`, `failed`, and how many still wait.
	 *
	 * @spec openspec/changes/flows-hand-off-and-wait/specs/remote-agents/spec.md#requirement-a-remote-task-travels-through-integriq-and-may-finish-later-req-a2a-003
	 */
	public function run(): array {
		$counts = ['expired' => 0, 'done' => 0, 'failed' => 0, 'waiting' => 0];
		foreach ($this->handoffs->allWaiting() as $handoffId => $handoff) {
			try {
				$outcome = $this->sweepOne(handoffId: (string) $handoffId, handoff: $handoff);
			} catch (Throwable $e) {
				$this->logger->warning('[HandoffSweep] A handoff could not be swept: ' . $e->getMessage(), ['handoffId' => $handoffId]);
				$outcome = 'waiting';
			}

			$counts[$outcome] = (($counts[$outcome] ?? 0) + 1);
		}

		return $counts;
	}//end run()

	/**
	 * Expire one handoff, or ask its remote task.
	 *
	 * @param string               $handoffId The handoff.
	 * @param array<string, mixed> $handoff   Its stored data.
	 *
	 * @return string `expired`, `done`, `failed` or `waiting`.
	 */
	private function sweepOne(string $handoffId, array $handoff): string {
		if ($this->handoffs->expireIfDue(handoffId: $handoffId) === true) {
			return 'expired';
		}

		if (($handoff['kind'] ?? null) !== 'a2a') {
			return 'waiting';
		}

		$outcome = $this->remoteTasks->poll(handoffId: $handoffId, handoff: $handoff);
		if ($outcome === 'unreachable') {
			return 'waiting';
		}

		return $outcome;
	}//end sweepOne()
}//end class
