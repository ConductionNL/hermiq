<?php

/**
 * Hermiq tool-access request resolution.
 *
 * One owner's decision on one tool-access request, as ToolOversightController
 * carries it from its guards to its write: the agent and request in the path, the
 * deciding user, the decision, and the stored request it is written onto. Built by
 * that controller and never handed out of it.
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
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * One tool-access request resolution.
 *
 * @spec openspec/changes/tool-discovery-and-access-requests/specs/tool-discovery-and-access-requests/spec.md#requirement-only-the-agents-owner-must-be-able-to-grant-a-request
 */
final class ToolAccessResolution {

	/**
	 * The stored request, with the decision written onto it.
	 *
	 * @var array<string, mixed>
	 */
	public array $data = [];

	/**
	 * The decision: `granted` or `refused`.
	 *
	 * @var string
	 */
	public string $decision = '';

	/**
	 * Start the resolution.
	 *
	 * @param string $agentId The agent in the path.
	 * @param string $requestId The request in the path.
	 * @param ObjectEntity $agent The agent, owned by the deciding user.
	 * @param string $decidedBy The deciding user's uid.
	 */
	public function __construct(
		public readonly string $agentId,
		public readonly string $requestId,
		public readonly ObjectEntity $agent,
		public readonly string $decidedBy,
	) {
	}//end __construct()
}//end class
