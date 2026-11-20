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
 * @spec openspec/changes/archive/2026-07-13-agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\AgentAccessServiceInterface;
use OCA\Hermiq\Kernel\Contracts\AgentVersionServiceInterface;
use OCA\Hermiq\Service\AgentBuildService;
use OCA\Hermiq\Service\Workspace\WorkspaceException;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserManager;
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
 * @spec openspec/changes/archive/2026-07-13-agent-versioning/tasks.md#task-2-agentversioncontroller-routes-owner-scoped-readrollback-endpoints
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) carried in with this batch's feature work; splitting it is
 * follow-up work, not part of this merge.
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
	 * @param AgentVersionServiceInterface $agentVersionService Reads/diffs/rolls back the agent's version history.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param AgentAccessServiceInterface $agentAccess The one per-agent access predicate.
	 * @param AgentBuildService|null $agentBuilds Publishes, lists and re-pins agent builds.
	 * @param IUserManager|null $userManager Resolves a publisher's display name.
	 *
	 * @spec openspec/specs/agent-versioning/spec.md
	 */
	public function __construct(
		IRequest $request,
		private readonly ObjectService $objectService,
		private readonly AgentVersionServiceInterface $agentVersionService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly AgentAccessServiceInterface $agentAccess,
		private readonly ?AgentBuildService $agentBuilds = null,
		private readonly ?IUserManager $userManager = null,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
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
			options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA)
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
			if ($this->agentBuilds === null) {
				return new JSONResponse($this->serializeAgent(agent: $updated));
			}

			return new JSONResponse($this->withRepin(agent: $updated, versionId: $versionId));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Hermiq agent-version rollback failed: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse(['error' => 'Could not roll back to the requested version'], Http::STATUS_BAD_REQUEST);
		}//end try

	}//end rollback()

	/**
	 * The agent's builds, the pinned one and whether the agent changed since it.
	 *
	 * @param string $id The Agent UUID.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#scenario-edits-after-publishing-are-visible-to-the-owner
	 */
	public function builds(string $id): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
		if ($agent === null || $this->agentBuilds === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$overview = $this->agentBuilds->overview(agent: $agent);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-build list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the builds'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		$overview['builds'] = array_map(fn (array $build): array => $this->withPublisherName(build: $build), $overview['builds']);
		$overview['canPublish'] = $this->agentAccess->canUserModifyAgent(agent: $agent, userId: $userId);
		return new JSONResponse($overview);
	}//end builds()

	/**
	 * Publish a build from the caller's workspace in one conversation (owner-only).
	 *
	 * @param string $id The Agent UUID.
	 *
	 * @return JSONResponse The build, or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-an-agent-owner-can-publish-a-build-that-pins-files-and-installed-tools-req-build-001
	 */
	public function publishBuild(string $id): JSONResponse {
		$userId = (string)$this->userSession->getUser()?->getUID();

		$agent = $this->loadAccessibleAgent(id: $id, userId: $userId);
		if ($agent === null || $this->agentBuilds === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
			return new JSONResponse(['error' => 'You do not have permission to publish a build of this agent'], Http::STATUS_FORBIDDEN);
		}

		$sessionId = trim((string)$this->request->getParam('sessionId', ''));
		if ($sessionId === '') {
			return new JSONResponse(['error' => 'sessionId is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$build = $this->agentBuilds->publish(
				agent: $agent,
				sessionId: $sessionId,
				userId: $userId,
				note: (string)$this->request->getParam('note', '')
			);
		} catch (WorkspaceException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-build publish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not publish the build'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		return new JSONResponse($this->withPublisherName(build: $build), Http::STATUS_CREATED);
	}//end publishBuild()

	/**
	 * The rolled-back agent with the build re-pinned for that version, when it has one.
	 *
	 * @param ObjectEntity $agent     The agent as the rollback saved it.
	 * @param string       $versionId The version rolled back to.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/tools-agent-workspace-and-installed-tools/specs/agent-versioning/spec.md#requirement-rolling-back-re-pins-the-matching-build-req-build-003
	 */
	private function withRepin(ObjectEntity $agent, string $versionId): array {
		$serialized = $this->serializeAgent(agent: $agent);
		try {
			$repin = ['pinned' => false, 'buildId' => null, 'buildNumber' => null];
			if ($this->agentBuilds !== null) {
				$repin = $this->agentBuilds->repinForVersion(agent: $agent, versionId: $versionId);
			}
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq agent-build re-pin failed: ' . $e->getMessage(), ['exception' => $e]);
			$repin = ['pinned' => false, 'buildId' => ($serialized['pinnedBuildId'] ?? null), 'buildNumber' => null];
		}

		$repin['message'] = 'This version has no build. The pinned build stays as it was.';
		if ($repin['pinned'] === true) {
			$serialized['pinnedBuildId'] = $repin['buildId'];
			$repin['message'] = 'Build ' . (string)$repin['buildNumber'] . ' is pinned again.';
		}

		$serialized['build'] = $repin;
		return $serialized;
	}//end withRepin()

	/**
	 * A build with its publisher's display name.
	 *
	 * @param array<string, mixed> $build The build.
	 *
	 * @return array<string, mixed>
	 */
	private function withPublisherName(array $build): array {
		$uid = (string)($build['createdBy'] ?? '');
		$build['createdByName'] = '';
		if ($uid !== '') {
			$build['createdByName'] = ($this->userManager?->getDisplayName($uid) ?? $uid);
		}
		return $build;
	}//end withPublisherName()

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
				options: new ObjectFindOptions(register: self::REGISTER_SLUG, schema: self::AGENT_SCHEMA)
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
