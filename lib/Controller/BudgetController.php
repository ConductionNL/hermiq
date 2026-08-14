<?php

/**
 * Hermiq BudgetController.
 *
 * The budget-guardrails surface (cost-guardrails): CRUD for per-organisation/per-agent
 * `Budget` objects, current-period status, and the pre-run cost estimate. Read endpoints
 * (`index`, `status`, `estimate`) are `@NoAdminRequired` + tenant-scoped via
 * `BudgetService` (RBAC/multitenancy ON) — a caller-supplied `organisation`/`agentId`
 * never itself grants visibility. Write endpoints (`create`/`update`/`destroy`) require
 * the Nextcloud instance admin OR the owner of the target OpenRegister organisation,
 * mirroring `TenantControlController::mayAdminister()` exactly.
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
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use InvalidArgumentException;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\BudgetService;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped budget CRUD + status + estimate endpoints.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One injected collaborator per seam
 *   (budget service, user session, group manager, org mapper, logger) plus the HTTP
 *   response/exception types every endpoint returns.
 *
 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
 */
class BudgetController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param BudgetService $budgetService The budget read/write service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param IGroupManager $groupManager Instance-admin check.
	 * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup (owner check).
	 * @param LoggerInterface $logger PSR-3 logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly BudgetService $budgetService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List budgets (organisation-scope and agent-scope) visible to the caller,
	 * optionally narrowed to one organisation.
	 *
	 * @param string $organisation Optional organisation filter.
	 *
	 * @return JSONResponse The budget list, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function index(string $organisation = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse(['budgets' => $this->budgetService->listForCaller(organisation: $organisation)]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load budgets'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Current-period usage vs. limit for one scope, tenant-scoped.
	 *
	 * @param string $organisation The organisation identifier.
	 * @param string $agentId Optional agent UUID (agent-scoped budget).
	 *
	 * @return JSONResponse The status payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function status(string $organisation = '', string $agentId = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$resolvedAgentId = null;
			if ($agentId !== '') {
				$resolvedAgentId = $agentId;
			}

			return new JSONResponse($this->budgetService->statusForScope(organisation: $organisation, agentId: $resolvedAgentId));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget status failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load budget status'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end status()

	/**
	 * Pre-run rough cost estimate for one agent (run-analytics).
	 *
	 * @param string $agentId The agent UUID.
	 *
	 * @return JSONResponse The estimate payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/cost-guardrails/specs/run-analytics/spec.md#requirement-pre-run-cost-estimate-derived-from-trailing-per-agent-run-history
	 */
	public function estimate(string $agentId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			return new JSONResponse($this->budgetService->estimateNextRun(agentId: $agentId));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget estimate failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load the cost estimate'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end estimate()

	/**
	 * Create a budget for an organisation. Admin/owner-gated.
	 *
	 * @return JSONResponse The created budget, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$organisation = (string)$this->request->getParam('organisation', '');
		if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->request->getParams();
			return new JSONResponse($this->budgetService->create(payload: $payload, organisation: $organisation));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create budget'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end create()

	/**
	 * Update a budget. Admin/owner-gated (checked against the EXISTING budget's
	 * organisation, not a caller-supplied one).
	 *
	 * @param string $budgetId The Budget object UUID.
	 *
	 * @return JSONResponse The updated budget, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function update(string $budgetId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->budgetService->findById(budgetId: $budgetId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');
		if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$payload = $this->request->getParams();
			return new JSONResponse($this->budgetService->update(budgetId: $budgetId, payload: $payload));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update budget'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end update()

	/**
	 * Delete a budget. Admin/owner-gated (checked against the EXISTING budget's
	 * organisation).
	 *
	 * @param string $budgetId The Budget object UUID.
	 *
	 * @return JSONResponse An empty success payload, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	public function destroy(string $budgetId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$existing = $this->budgetService->findById(budgetId: $budgetId);
		if ($existing === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$organisation = (string)($existing->getOrganisation() ?? '');
		if ($this->mayAdminister(organisation: $organisation, user: $user) === false) {
			return new JSONResponse(['error' => 'Forbidden'], Http::STATUS_FORBIDDEN);
		}

		try {
			$this->budgetService->delete(budgetId: $budgetId);
			return new JSONResponse(['deleted' => true]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq budget delete failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not delete budget'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end destroy()

	/**
	 * Whether the user may administer budgets for the organisation. Instance admin OR
	 * the owner of the target OpenRegister organisation — mirrors
	 * TenantControlController::mayAdminister() exactly.
	 *
	 * @param string $organisation The organisation identifier (OpenRegister org UUID).
	 * @param IUser $user The requesting user.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/multi-tenant-ops/spec.md#requirement-per-scope-budget-guardrails-soft-threshold-and-hard-cap
	 */
	private function mayAdminister(string $organisation, IUser $user): bool {
		if ($organisation === '') {
			return false;
		}

		if ($this->groupManager->isAdmin($user->getUID()) === true) {
			return true;
		}

		try {
			$org = $this->organisationMapper->findByUuid($organisation);
		} catch (Throwable $e) {
			return false;
		}

		return (string)($org->getOwner() ?? '') === $user->getUID();
	}//end mayAdminister()
}//end class
