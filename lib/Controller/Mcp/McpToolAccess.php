<?php

/**
 * Hermiq McpToolAccess
 *
 * What decides and runs a governed MCP tool call.
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

use OCA\Hermiq\Kernel\Contracts\GuardrailPolicyServiceInterface;
use OCA\Hermiq\Kernel\Contracts\ToolLoopInterface;
use OCA\Hermiq\Kernel\Contracts\ToolSearchServiceInterface;
use OCA\OpenRegister\Mcp\Service\Mcp\ToolRegistryFacade;
use OCA\OpenRegister\Service\Capability\ToolGrantResolver;

/**
 * What decides and runs a governed MCP tool call.
 *
 * @spec exclude Parameter object, no functional requirement of its own.
 */
final class McpToolAccess {

	/**
	 * Constructor.
	 *
	 * @param ToolRegistryFacade $toolRegistryFacade OR's public tool read/invoke surface.
	 * @param ToolGrantResolver $grantResolver Expands `Agent.tools` against the catalog.
	 * @param ToolLoopInterface $toolLoop Builds the governed `FacadeToolInvoker` (reused,
	 *                                    not reimplemented).
	 * @param ToolSearchServiceInterface $toolSearchService Holds the run's resolved set for the approval gate.
	 * @param GuardrailPolicyServiceInterface|null $guardrailService Applies the output filters to
	 *                                                               the steps shown in the chat.
	 *
	 * @spec exclude Parameter object, no functional requirement of its own.
	 */
	public function __construct(
		public readonly ToolRegistryFacade $toolRegistryFacade,
		public readonly ToolGrantResolver $grantResolver,
		public readonly ToolLoopInterface $toolLoop,
		public readonly ToolSearchServiceInterface $toolSearchService,
		public readonly ?GuardrailPolicyServiceInterface $guardrailService = null,
	) {
	}//end __construct()
}//end class
