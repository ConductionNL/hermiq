<?php

/**
 * Hermiq McpRunState
 *
 * The run an MCP call belongs to: its objects, steps, workspace and call count.
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller\Mcp;

use OCA\Hermiq\Kernel\Contracts\RunStepBusInterface;
use OCA\Hermiq\Service\Engine\RunToolCallCounter;
use OCA\Hermiq\Service\Workspace\WorkspaceRunScope;
use OCA\OpenRegister\Service\ObjectService;

/**
 * The run an MCP call belongs to: its objects, steps, workspace and call count.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class McpRunState {

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService Loads the run's agent (under the impersonated user).
	 * @param RunStepBusInterface $runStepBus Publishes run steps to whichever surface is watching.
	 * @param WorkspaceRunScope|null $workspaceScope Carries the token's run to the governed
	 *                                               workspace tools for the length of one dispatch.
	 * @param RunToolCallCounter|null $toolCallCounter Counts the run's tool calls across requests,
	 *                                                 for the agent's tool call cap.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ObjectService $objectService,
		public readonly RunStepBusInterface $runStepBus,
		public readonly ?WorkspaceRunScope $workspaceScope = null,
		public readonly ?RunToolCallCounter $toolCallCounter = null,
	) {
	}//end __construct()
}//end class
