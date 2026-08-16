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
 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
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
 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
 */
class HermiqFlowNodeListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param HermiqAgentNode $agentNode The agent step node.
	 * @param HermiqWorkloadNode $workloadNode The workload step node.
	 * @param HermiqWorkloadCollectNode $collectNode Collects a workload started asynchronously.
	 */
	public function __construct(
		private readonly HermiqAgentNode $agentNode,
		private readonly HermiqWorkloadNode $workloadNode,
		private readonly HermiqWorkloadCollectNode $collectNode,
	) {

	}//end __construct()

	/**
	 * Contribute hermiq's nodes.
	 *
	 * Two, and they are the two things a flow cannot do for itself: run a model
	 * turn, and run a command over a checked-out tree.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/consume-or-flow-engine/specs/or-flow-consumer/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof RegisterFlowNodesEvent) === false) {
			return;
		}

		$event->registerNode(node: $this->agentNode);
		$event->registerNode(node: $this->workloadNode);
		$event->registerNode(node: $this->collectNode);

	}//end handle()
}//end class
