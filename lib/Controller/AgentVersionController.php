<?php

/**
 * Hermiq AgentVersionController.
 *
 * Owner/invited-scoped REST endpoints over Agent-versioning's read + rollback
 * surface (AgentVersionService), itself a thin read/replay layer over
 * OpenRegister's existing hash-chained AuditTrail — no new storage. Access
 * is AgentAccessService's one predicate (`canUserAccessAgent()` for the two
 * read endpoints, `canUserModifyAgent()` for rollback), the same one every
 * other agent route uses (agents-sharing-and-catalog-columns, design D2).
 *
 * @category Controller
 * @package  OCA\Hermiq\Controller
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
 * @spec openspec/changes/agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AgentVersionService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owner/invited-scoped read + owner-only rollback endpoints over an Agent's
 * version history (agent-versioning).
 *
 * Every `@NoAdminRequired` method guards per-object access/ownership in the
 * method body (gate-7 no-admin-idor), exactly like AgentsController.
 *
 * @spec openspec/changes/agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 */
class AgentVersionController extends Controller {

	/**
	 * OpenRegister register slug that holds Hermiq agent-engine objects.
	 *
	 * @var string
	 */
	private const REGISTER_SLUG = 'hermiq';

	/**
	 * OpenRegister schema slug for agent objects.
	 *
	 * @var string
	 */
	private const AGENT_SCHEMA = 'agent';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param ObjectService $objectService OpenRegister object read (owner/access guard).
	 * @param AgentVersionService $agentVersionService Reads/diffs/rolls back the agent's version history.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param AgentAccessService $agentAccess The one per-agent access predicate.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly AgentVersionService $agentVersionService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly AgentAccessService $agentAccess,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List an agent's version history (newest-first).
	 *
	 * @param string $id The Agent UUID.
	 *
	 * @return JSONResponse The version list, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	public function index(string $id): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$versions = $this->agentVersionService->listVersions(agentUuid: $id);
			return new JSONResponse(['results' => $versions, 'total' => count($versions)]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Hermiq agent-version list failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(['error' => 'Could not load version history'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end index()

	/**
	 * Diff two of an agent's versions across the versioned-config field set.
	 *
	 * @param string $id The Agent UUID.
	 *
	 * @return JSONResponse The diff, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-diff-two-agent-versions-across-the-versioned-config-field-set
	 */
	public function diff(string $id): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		$from = (string)$this->request->getParam('from', '');
		$to = (string)$this->request->getParam('to', '');
		if ($from === '' || $to === '') {
			return new JSONResponse(['error' => 'from and to are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$diff = $this->agentVersionService->diff(agentUuid: $id, fromId: $from, toId: $to);
			return new JSONResponse(['results' => $diff]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq agent-version diff failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(['error' => 'Could not diff the requested versions'], Http::STATUS_BAD_REQUEST);
		}//end try

	}//end diff()

	/**
	 * Roll an agent back to a previous version's config values (owner-only).
	 *
	 * @param string $id The Agent UUID.
	 * @param string $versionId The target version's AuditTrail entry UUID.
	 *
	 * @return JSONResponse The updated agent, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
	 */
	public function rollback(string $id, string $versionId): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$agent = $this->objectService->find(
			id: $id,
			register: self::REGISTER_SLUG,
			schema: self::AGENT_SCHEMA
		);
		if (($agent instanceof ObjectEntity) === false) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		// Owner-only modification guard (gate-7) — mirrors AgentsController::update().
		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
			return new JSONResponse(
				['error' => 'You do not have permission to roll back this agent'],
				Http::STATUS_FORBIDDEN
			);
		}

		try {
			$updated = $this->agentVersionService->rollback(agentUuid: $id, versionId: $versionId);
			return new JSONResponse($this->serializeAgent(agent: $updated));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq agent-version rollback failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(['error' => 'Could not roll back to the requested version'], Http::STATUS_BAD_REQUEST);
		}//end try

	}//end rollback()

	/**
	 * Load an agent only when it exists AND the requesting user may read it
	 * (gate-7 no-admin-idor) — a 404 either way, so a non-owner cannot even
	 * confirm a private agent exists.
	 *
	 * @param string $id The Agent UUID.
	 * @param string $userId The requesting user's UID.
	 *
	 * @return ObjectEntity|null The accessible agent, or null.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-list-an-agents-version-history
	 */
	private function loadAccessibleAgent(string $id, string $userId): ?ObjectEntity {
		try {
			$agent = $this->objectService->find(
				id: $id,
				register: self::REGISTER_SLUG,
				schema: self::AGENT_SCHEMA
			);
		} catch (Throwable $e) {
			// `ObjectService::find()` documents `@throws Exception If the object
			// is not found`, and every caller invokes this helper OUTSIDE its own
			// try block — so an unhandled throw escapes to the dispatcher as a
			// framework 500 with a stack trace on a #[NoAdminRequired] route. An
			// agent that cannot be loaded is, to a caller, not accessible, which
			// is exactly what null already means here.
			$this->logger->warning(
				'Hermiq agent lookup failed for ' . $id . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return null;
		}//end try

		if (($agent instanceof ObjectEntity) === false) {
			return null;
		}

		if ($this->agentAccess->canUserAccessAgent(agent: $agent, userId: $userId) === false) {
			return null;
		}

		return $agent;
	}//end loadAccessibleAgent()

	/**
	 * Serialize an agent object to the OR-compatible response shape — mirrors
	 * `AgentsController::serializeAgent()`.
	 *
	 * @param ObjectEntity $agent The agent object.
	 *
	 * @return array<string, mixed> Serialized agent.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md#requirement-roll-back-an-agent-to-a-previous-version-without-mutating-history
	 */
	private function serializeAgent(ObjectEntity $agent): array {
		return array_merge(
			$agent->getObject(),
			[
				'id' => $agent->getUuid(),
				'uuid' => $agent->getUuid(),
				'owner' => $agent->getOwner(),
				'organisation' => $agent->getOrganisation(),
				'created' => $agent->getCreated()?->format('c'),
				'updated' => $agent->getUpdated()?->format('c'),
			]
		);

	}//end serializeAgent()
}//end class
