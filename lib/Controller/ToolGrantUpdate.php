<?php

/**
 * Hermiq tool-invocation export.
 *
 * One owner's update of an agent's tool grants: the agent in the path and the grants
 * in the storable form the write path takes.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/agent-tool-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

/**
 * One tool-grant update.
 *
 * @spec openspec/specs/agent-tool-governance/spec.md
 */
final class ToolGrantUpdate {

	/**
	 * The update.
	 *
	 * @param string $agentId Agent UUID, as in the path.
	 * @param array $grants The storable grants.
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly array $grants,
	) {
	}//end __construct()
}//end class
