<?php

/**
 * Minimal OpenRegister RegisterFlowNodesEvent stub for standalone unit runs
 * and static analysis.
 *
 * Signatures mirrored from openregister lib/Service/Flow/RegisterFlowNodesEvent.php.
 * The real event delegates `registerNode()` to a FlowNodeRegistry; the stub
 * collects nodes locally so listeners are exercisable without OpenRegister.
 * Registered at TEST TIME only by tests/bootstrap.php and scanned (never
 * executed) by phpstan/psalm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCP\EventDispatcher\Event;

/**
 * Minimal RegisterFlowNodesEvent stub.
 */
class RegisterFlowNodesEvent extends Event {

	/**
	 * Contributed node types.
	 *
	 * @var array<int, IFlowNode>
	 */
	private array $nodes = [];

	/**
	 * Contribute a node type.
	 *
	 * @param IFlowNode $node The node type.
	 *
	 * @return void
	 */
	public function registerNode(IFlowNode $node): void {
		$this->nodes[] = $node;

	}//end registerNode()

	/**
	 * Every contributed node type.
	 *
	 * @return array<int, IFlowNode> The node types.
	 */
	public function getNodes(): array {
		return $this->nodes;
	}//end getNodes()
}//end class
