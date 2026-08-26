<?php

/**
 * Hermiq AgentRunRequestedJob.
 *
 * One-shot QueuedJob that performs the actual governed agent run for a flow-
 * triggered `AgentRunRequestedEvent` (ADR-041). Enqueued by
 * AgentRunRequestedListener via `IJobList::add()` so the triggering OpenRegister
 * save/request never blocks on the agent turn. All logic lives in
 * FlowAgentRunService — this class stays a pure wrapper (ADR-002), exactly like
 * ScheduleTask wraps ScheduleService.
 *
 * @category Cron
 * @package  OCA\Hermiq\BackgroundJob
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
 * @spec openspec/changes/flow-agent-listener/tasks.md#1-listener-and-queued-job
 */

declare(strict_types=1);

namespace OCA\Hermiq\BackgroundJob;

use OCA\Hermiq\Service\FlowAgentRunService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;

/**
 * Background job that runs one governed flow-triggered agent run.
 *
 * @psalm-api
 *
 * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-3
 */
class AgentRunRequestedJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for the base job.
	 * @param FlowAgentRunService $flowAgentRunService Runs the governed dispatch.
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly FlowAgentRunService $flowAgentRunService,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the job: delegate the whole governed dispatch to FlowAgentRunService.
	 *
	 * @param mixed $argument The AgentRunRequestedEvent payload (scalar-only array —
	 *                        see AgentRunRequestedEvent::getPayload()). Defensively
	 *                        checked below: `IJobList` argument storage is a JSON
	 *                        round-trip, not a compile-time-guaranteed array.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-agent-listener/tasks.md#task-1-3
	 */
	protected function run($argument): void {
		$payload = $argument;
		if (is_array($payload) === false) {
			$payload = [];
		}

		$this->flowAgentRunService->run(payload: $payload);

	}//end run()
}//end class
