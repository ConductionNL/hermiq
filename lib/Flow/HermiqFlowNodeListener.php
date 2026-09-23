<?php

/**
 * Contributes hermiq's agent node to OpenRegister's flow engine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * @category Flow
 * @package  OCA\Hermiq\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Flow;

use OCA\OpenRegister\Service\Flow\RegisterFlowNodesEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Registers the agent node when OpenRegister builds its node palette.
 *
 * @template-implements IEventListener<RegisterFlowNodesEvent>
 *
 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
class HermiqFlowNodeListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param HermiqAgentNode $agentNode The agent step node.
	 * @param HermiqWorkloadNode $workloadNode The workload step node.
	 * @param HermiqWorkloadCollectNode $collectNode Collects a workload started asynchronously.
	 * @param HermiqScheduleDispatchNode $scheduleDispatchNode Fires one schedule occurrence
	 *                                                         through the governed dispatch
	 *                                                         path (schedules-onto-engine-triggers).
	 * @param GitHubAwaitLabelNode $awaitLabelNode Holds a run until a label lands on an issue.
	 */
	public function __construct(
		private readonly HermiqAgentNode $agentNode,
		private readonly HermiqWorkloadNode $workloadNode,
		private readonly HermiqWorkloadCollectNode $collectNode,
		private readonly HermiqScheduleDispatchNode $scheduleDispatchNode,
		private readonly GitHubAwaitLabelNode $awaitLabelNode,
	) {

	}//end __construct()

	/**
	 * Contribute hermiq's nodes.
	 *
	 * What they have in common is that a flow cannot do any of them for itself:
	 * run a model turn, run a command over a checked-out tree, and wait on a
	 * decision that only exists on someone else's forge.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-09-02-consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		$event->registerNode(node: $this->agentNode);
		$event->registerNode(node: $this->workloadNode);
		$event->registerNode(node: $this->collectNode);
		$event->registerNode(node: $this->scheduleDispatchNode);
		$event->registerNode(node: $this->awaitLabelNode);

	}//end handle()
}//end class
