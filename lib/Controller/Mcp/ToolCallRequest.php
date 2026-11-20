<?php

/**
 * Hermiq ToolCallRequest
 *
 * One MCP tool call and the agent and conversation it runs for.
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

/**
 * One MCP tool call and the agent and conversation it runs for.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class ToolCallRequest {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $params The call params (`name`, `arguments`).
	 * @param string $agentId The run's agent UUID.
	 * @param string $conversationId The conversation this run belongs to, so a step
	 *                               published on the bus reaches the right stream.
	 * @param string $projectKey The run's project, from the token; '' outside a project.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly array $params,
		public readonly string $agentId,
		public readonly string $conversationId = '',
		public readonly string $projectKey = '',
	) {
	}//end __construct()
}//end class
