<?php

/**
 * Hermiq ToolOversightServices.
 *
 * The collaborators of the tool-oversight endpoints, injected as one.
 *
 * @category Service
 * @package  OCA\Hermiq\Service\ToolOversight
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
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 */

declare(strict_types=1);

namespace OCA\Hermiq\Service\ToolOversight;

/**
 * The agent lookup, the catalogue, the invocation log and the grant writes.
 *
 * @psalm-api
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 */
final class ToolOversightServices {

	/**
	 * Constructor.
	 *
	 * @param ToolOversightAgents $agents Loads an agent and decides who may view its oversight.
	 * @param ToolCatalogView $catalog The grant-annotated tool catalogue.
	 * @param ToolInvocationLog $invocations The tool-invocation rows and their export.
	 * @param ToolGrantWrites $grants Grant and access-request writes.
	 */
	public function __construct(
		public readonly ToolOversightAgents $agents,
		public readonly ToolCatalogView $catalog,
		public readonly ToolInvocationLog $invocations,
		public readonly ToolGrantWrites $grants,
	) {
	}//end __construct()
}//end class
