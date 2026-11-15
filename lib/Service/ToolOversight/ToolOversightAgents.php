<?php

/**
 * Hermiq ToolOversightAgents.
 *
 * Loads the agent a tool-oversight request names, and decides whether a user
 * may view its tool oversight.
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

use OCA\Hermiq\Service\AgentAccessService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The agent lookup and the view check of the tool-oversight endpoints.
 *
 * @psalm-api
 *
 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
 */
class ToolOversightAgents {

	/**
	 * OpenRegister register slug that holds Hermiq objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * Schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService OpenRegister object read.
	 * @param AgentAccessService $agentAccess The one per-agent access predicate.
	 * @param IGroupManager $groupManager Instance-admin check for the oversight bypass.
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AgentAccessService $agentAccess,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Load an agent (tenant-scoped by `ObjectService` multitenancy/RBAC, exactly
	 * like `AgentsController::show()`).
	 *
	 * @param string $agentId Agent UUID.
	 *
	 * @return ObjectEntity|null
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
	 */
	public function find(string $agentId): ?ObjectEntity {
		$agent = null;
		try {
			$agent = $this->objectService->find(id: $agentId, register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent, and the
			// endpoints call this OUTSIDE their own try block, so the throw would
			// escape as a framework 500 with a stack trace on a #[NoAdminRequired]
			// route. An agent that cannot be loaded is, to a caller, not found;
			// null already carries that.
			$this->logger->warning(
				'Hermiq agent lookup failed for ' . $agentId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
		}//end try

		if (($agent instanceof ObjectEntity) === true) {
			return $agent;
		}

		return null;
	}//end find()

	/**
	 * Whether the user may view an agent's tool oversight: AgentAccessService's
	 * one predicate (non-private, owner, invited or a member of one of the
	 * agent's groups), plus the instance-admin oversight bypass below.
	 *
	 * @param ObjectEntity $agent Agent object.
	 * @param string $userId Nextcloud user id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-tool-governance-and-disclosure/tasks.md#task-5-tooloversightcontroller-routes-catalog-grants-invocations
	 */
	public function canAccess(ObjectEntity $agent, string $userId): bool {
		if ($this->agentAccess->canUserAccessAgent(agent: $agent, userId: $userId) === true) {
			return true;
		}

		// Instance-admin oversight bypass. This is a governance surface (EU AI Act
		// art.12/14 tool-invocation oversight), so an instance admin must be able to
		// inspect any agent's tool catalogue/activity, including system-owned seeded
		// agents (owner `__system__`) that no human owns and OpenRegister lets admins
		// read anyway. Deliberately scoped to the tool-oversight endpoints; it does
		// NOT widen general private-agent access elsewhere.
		return $this->groupManager->isAdmin($userId);
	}//end canAccess()
}//end class
