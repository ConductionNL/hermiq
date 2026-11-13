<?php

/**
 * Hermiq OrganisationOpsController.
 *
 * The org-operations surface (multi-tenant-ops + agent-lifecycle-governance): report the
 * caller's per-organisation quota usage, produce a per-tenant EU AI Act audit export, and
 * (agent-lifecycle-governance) the periodic access-review list + reviewed attestation +
 * flagged-agent reassignment, incident records, and the retention-period setting. Reads
 * are tenant-scoped inside OrganisationOpsService (they only ever read the caller's own Hermiq
 * objects), so no cross-tenant data leaks; `@NoAdminRequired` opens the read routes to any
 * authenticated user with tenancy as the guard (the UI additionally gates visibility to org
 * owners/admins). The four MUTATING endpoints (attest/reassign/createIncident/retention PUT)
 * additionally gate through `ActionAuthService::requireAction()` (ADR-023) — these are
 * governance actions, not ordinary CRUD (OWASP A01 / ADR-005 Rule 3).
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
 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#2-controller-routes
 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\Kernel\AppIdentity;
use OCA\Hermiq\Kernel\Contracts\OrganisationOpsServiceInterface;
use OCA\Hermiq\Service\ActionAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped quota + AI Act audit-export + agent-lifecycle-governance endpoints.
 *
 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#2-controller-routes
 */
class OrganisationOpsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param OrganisationOpsServiceInterface $tenantOpsService The tenant-ops read/write service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param ActionAuthService $actionAuth ADR-023 action-authorization gate for the
	 *                                      four mutating endpoints (agent-lifecycle-governance).
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: distinct collaborators.
	 *
	 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-2-1
	 */
	public function __construct(
		IRequest $request,
		private readonly OrganisationOpsServiceInterface $tenantOpsService,
		private readonly IUserSession $userSession,
		private readonly ActionAuthService $actionAuth,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Report the caller's organisation quota usage against the configured limits.
	 *
	 * @return JSONResponse The quota status, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-2-1
	 */
	public function quota(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->tenantOpsService->quotaStatus());
		} catch (Throwable $e) {
			$this->logger->error('Hermiq quota status failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load quota'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end quota()

	/**
	 * Produce a per-tenant EU AI Act audit export (JSON) for the caller's organisation.
	 *
	 * @return JSONResponse The audit export, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-09-06-multi-tenant-ops/tasks.md#task-2-1
	 */
	public function auditExport(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$response = new JSONResponse($this->tenantOpsService->exportAuditTrail());
			$response->addHeader('Content-Disposition', 'attachment; filename="hermiq-ai-act-audit.json"');
			return $response;
		} catch (Throwable $e) {
			$this->logger->error('Hermiq audit export failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not produce export'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end auditExport()

	/**
	 * List the caller's organisation's agents for the periodic access review
	 * (agent-lifecycle-governance).
	 *
	 * @return JSONResponse The access-review payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
	 */
	public function reviewList(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->tenantOpsService->accessReviewList());
		} catch (Throwable $e) {
			$this->logger->error('Hermiq access-review list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load access review'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end reviewList()

	/**
	 * Record a reviewed attestation for one Agent (action-auth-gated).
	 *
	 * @param string $uuid The Agent UUID.
	 *
	 * @return JSONResponse The updated access-review row, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
	 */
	public function attestReview(string $uuid): JSONResponse {
		$uid = $this->authorise(action: 'tenantops.attest-review');
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		try {
			return new JSONResponse($this->tenantOpsService->attestAgentReviewed(uuid: $uuid, reviewerUid: $uid));
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq review attestation failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Attestation failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end attestReview()

	/**
	 * Reassign a flagged Agent's `actingUser` to a new active user (action-auth-gated).
	 *
	 * @param string $uuid The Agent UUID.
	 *
	 * @return JSONResponse The updated access-review row, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-5-tenantopscontroller-access-review-reassign-endpoints
	 */
	public function reassignAgent(string $uuid): JSONResponse {
		$uid = $this->authorise(action: 'tenantops.reassign-agent');
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$actingUser = (string)$this->request->getParam('actingUser', '');
		if ($actingUser === '') {
			return new JSONResponse(['error' => 'actingUser is required'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		try {
			return new JSONResponse($this->tenantOpsService->reassignAgent(uuid: $uuid, newActingUser: $actingUser));
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent reassignment failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Reassignment failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end reassignAgent()

	/**
	 * List the caller's organisation's incident records (agent-lifecycle-governance).
	 *
	 * @return JSONResponse The incident list, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
	 */
	public function incidents(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->tenantOpsService->listIncidents());
		} catch (Throwable $e) {
			$this->logger->error('Hermiq incident list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load incidents'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end incidents()

	/**
	 * Open a new incident record (action-auth-gated).
	 *
	 * @return JSONResponse The created incident, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
	 */
	public function createIncident(): JSONResponse {
		$uid = $this->authorise(action: 'tenantops.create-incident');
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$description = (string)$this->request->getParam('description', '');
		$impact = (string)$this->request->getParam('impact', '');
		$actionsTaken = (string)$this->request->getParam('actionsTaken', '');
		if ($description === '' || $impact === '' || $actionsTaken === '') {
			return new JSONResponse(
				['error' => 'description, impact, and actionsTaken are required'],
				Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}

		try {
			$incident = $this->tenantOpsService->createIncident(
				description: $description,
				impact: $impact,
				actionsTaken: $actionsTaken,
				linkedAgentId: $this->linkedAgentIdParam(),
				linkedRunIds: $this->linkedRunIdsParam(),
				createdBy: $uid
			);
			return new JSONResponse($incident, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq incident create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create the incident'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end createIncident()

	/**
	 * The `linkedAgentId` param as a string, or null when absent.
	 *
	 * @return string|null
	 */
	private function linkedAgentIdParam(): ?string {
		$linkedAgentId = $this->request->getParam('linkedAgentId');
		if ($linkedAgentId === null) {
			return null;
		}

		return (string)$linkedAgentId;
	}//end linkedAgentIdParam()

	/**
	 * The `linkedRunIds` param, or [] when it is not a list.
	 *
	 * @return array
	 */
	private function linkedRunIdsParam(): array {
		$linkedRunIds = $this->request->getParam('linkedRunIds', []);
		if (is_array($linkedRunIds) === false) {
			return [];
		}

		return $linkedRunIds;
	}//end linkedRunIdsParam()

	/**
	 * The signed-in user's uid when they may perform the action, otherwise the
	 * 401/403 refusal (see ActionGate).
	 *
	 * @param string $action The ADR-023 action name to require.
	 *
	 * @return string|JSONResponse
	 */
	private function authorise(string $action): string|JSONResponse {
		$user = (new ActionGate(userSession: $this->userSession, actionAuth: $this->actionAuth))->authorise(action: $action);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		return $user->getUID();
	}//end authorise()

	/**
	 * Report the caller's organisation's currently configured retention period.
	 *
	 * @return JSONResponse The retention payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
	 */
	public function retention(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['retentionMonths' => $this->tenantOpsService->getRetentionMonths()]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq retention read failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load retention'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end retention()

	/**
	 * Configure the caller's organisation's retention period (action-auth-gated;
	 * rejects a value below the Art. 12 6-month minimum).
	 *
	 * @return JSONResponse The updated retention payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/archive/2026-07-13-agent-lifecycle-governance/tasks.md#task-7-tenantopscontroller-incidents-retention-endpoints
	 */
	public function updateRetention(): JSONResponse {
		$uid = $this->authorise(action: 'tenantops.update-retention');
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$months = (int)$this->request->getParam('retentionMonths', 0);

		try {
			$updated = $this->tenantOpsService->setRetentionMonths(months: $months);
			return new JSONResponse(['retentionMonths' => $updated]);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq retention update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update retention'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end updateRetention()
}//end class
