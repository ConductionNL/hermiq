<?php

/**
 * Hermiq AgentTemplateController.
 *
 * The agent-template-gallery surface: browse/CRUD the tenant-scoped AgentTemplate catalog,
 * export an existing Agent to a portable package, import a package (quarantined + content-
 * scanned when externally-sourced), approve a quarantined template (the review gate), and
 * "Use this template" instantiate into a real Agent. All reads/writes run in the caller's
 * session context through AgentTemplateService → OpenRegister ObjectService, so OR's native
 * RBAC scopes them to the caller's organisation — mirrors SkillController/
 * SkillMarketplaceController exactly.
 *
 * Security (ADR-005 Rule 3 / OWASP A01): `@NoAdminRequired` opens index/show/create/update/
 * destroy/export/import/instantiate to any authenticated user. ⚠️ Tenancy is NOT sufficient
 * for the three routes that take a caller-supplied id and act on it: the `AgentTemplate`
 * schema declares no `authorization` block, so OR's register RBAC is default-OPEN on it and
 * multitenancy scopes organisations rather than the users inside one. `update()` and
 * `destroy()` are therefore owner-guarded (`SeedCustodyService::actsAsOwner()`, the same
 * predicate the owner-guarded skill routes use, which also lets an admin curate the
 * `__system__`-owned seeded templates), and `export()` — which serialises an Agent's system
 * prompt, tools and skill refs — is guarded by `AgentAccessService`. The catalog stays
 * org-readable: `index`/`show` are unchanged. `approve()` is the one privileged mutation:
 * it gates on `ActionAuthService::requireAction()` (ADR-023), requiring
 * `agenttemplate.approve-quarantined`, and — when the caller passes `force=true` — additionally
 * `agenttemplate.override-scan-verdict` (a caller trusted to wave through a clean scan is not
 * automatically trusted to override a dangerous one). Both actions seed to admin-only.
 *
 * agent-template-github-store adds three GitHub-store endpoints on this SAME controller
 * (install/publish both operate on the same AgentTemplate resource): `githubSearch()` +
 * `githubInstall()` (browse/install from GitHub, `#[NoAdminRequired]` + in-body 401 guard,
 * mirrors OpenBuild's `ShopController`) and `publishGithub()` (publish an existing template
 * to a new tagged repo, tenant-scoped via the same `AgentTemplateService::get()`/
 * `exportTemplate()` path `show()`/`export()` already use). `githubInstall()` is a thin
 * adapter in front of the UNCHANGED `importPackage(source: 'hub')` — no new quarantine/scan
 * logic. The GitHub token never enters Hermiq: both new endpoints only ever pass a broker
 * `credentialId` to `GitHubTemplateCatalogService`/`GitHubTemplatePushService`.
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
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\ActionAuthService;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\AgentTemplateService;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Tenant-scoped agent-template-gallery endpoints.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList)   Constructor DI: each parameter is a
 *   distinct injected collaborator, not a logic-bearing argument list.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     A CRUD + gallery controller mirroring
 *   SkillController's (index/export/install) + SkillMarketplaceController's
 *   (installFromSource/approve) combined route surface, one method per HTTP endpoint.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   One injected collaborator per seam
 *   (template service, action auth, org mapper, GitHub catalog + push, OpenRegister
 *   object service) plus the HTTP response/exception types every endpoint returns.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small per-endpoint
 *   guard-and-delegate methods across the full CRUD + gallery + GitHub route surface,
 *   not one tangled algorithm.
 *
 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
 */
class AgentTemplateController extends Controller {
	/**
	 * Safe GitHub owner/repo pattern (agent-template-github-store), validated before
	 * any path interpolation on the search/install/publish endpoints — mirrors
	 * OpenBuild's `ShopController::OWNER_REPO_PATTERN` exactly.
	 *
	 * @var string
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Safe git-ref pattern (agent-template-github-store).
	 *
	 * @var string
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param AgentTemplateService $templateService The template read/write/gallery path.
	 * @param ActionAuthService $actionAuth The ADR-023 action-authorization service.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param OrganisationMapper $organisationMapper OpenRegister organisation lookup (instantiate's org resolution).
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param GitHubTemplateCatalogService $catalogService GitHub search/fetch (agent-template-github-store).
	 * @param GitHubTemplatePushService $pushService GitHub publish (agent-template-github-store).
	 * @param SeedCustodyService $seedCustody Owner-or-seed-custodian check (update/destroy guard).
	 * @param AgentAccessService $agentAccess Per-agent authorization (export guard).
	 * @param ObjectService $objectService OpenRegister object read/write — the ownership guard
	 *   reads, and `destroy()` deletes, through OpenRegister directly (ADR-022/ADR-083: no
	 *   app-local CRUD wrapper).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 */
	public function __construct(
		IRequest $request,
		private readonly AgentTemplateService $templateService,
		private readonly ActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly OrganisationMapper $organisationMapper,
		private readonly LoggerInterface $logger,
		private readonly GitHubTemplateCatalogService $catalogService,
		private readonly GitHubTemplatePushService $pushService,
		private readonly SeedCustodyService $seedCustody,
		private readonly AgentAccessService $agentAccess,
		private readonly ObjectService $objectService,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the tenant's templates.
	 *
	 * @return JSONResponse The templates list, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$templates = array_map([$this, 'shape'], $this->templateService->list());
			return new JSONResponse(['results' => $templates]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-templates list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load agent templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Author a new template directly — always `active`/`local`, never scanned.
	 *
	 * @return JSONResponse The created template, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function create(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$template = $this->templateService->create(payload: $this->request->getParams(), createdBy: $user->getUID());
			return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template create failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create the agent template: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

	}//end create()

	/**
	 * Update an existing template's fields.
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse The updated template, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function update(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->requireTemplateOwnership(templateId: $id) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$template = $this->templateService->update(templateId: $id, payload: $this->request->getParams());
			if ($template === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->shape(object: $template));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not update the agent template: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

	}//end update()

	/**
	 * Delete a template.
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse An empty success body, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function destroy(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->requireTemplateOwnership(templateId: $id) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			// Deleted through OpenRegister directly (ADR-022): a per-schema `delete()`
			// wrapper on AgentTemplateService added nothing but a second name for this
			// call. The authority to run it is the ownership guard four lines up.
			$this->objectService->deleteObject(
				uuid: $id,
				register: AgentTemplateService::REGISTER_SLUG,
				schema: AgentTemplateService::TEMPLATE_SCHEMA
			);
			return new JSONResponse([]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template delete failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not delete the agent template'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end destroy()

	/**
	 * Export an existing Agent to a portable template package.
	 *
	 * @param string $agentId The Agent UUID.
	 *
	 * @return JSONResponse The package string, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function export(string $agentId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->requireAgentReadAccess(agentId: $agentId) === false) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$package = $this->templateService->exportFromAgent(agentId: $agentId);
			if ($package === null) {
				return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['package' => $package]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template export failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end export()

	/**
	 * "Save as template" on the agent page: the owner turns the agent into an
	 * active template in the Store. 404 when the caller cannot read the agent, 403
	 * when they can but do not own it.
	 *
	 * @param string $agentId The Agent UUID.
	 *
	 * @return JSONResponse The new template (201), or an error status.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agent-can-be-saved-as-a-reusable-template-req-agexp-003
	 */
	public function saveFromAgent(string $agentId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->saveFromAgentRefusal(agentId: $agentId, userId: $user->getUID());
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->saveAgentAsTemplate(agentId: $agentId, userId: $user->getUID());

	}//end saveFromAgent()

	/**
	 * Refuse "save as template" for an agent the caller cannot read (404) or does not
	 * own (403).
	 *
	 * @param string $agentId The Agent UUID.
	 * @param string $userId The caller's UID.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller owns the agent.
	 */
	private function saveFromAgentRefusal(string $agentId, string $userId): ?JSONResponse {
		$agent = $this->agentAccess->loadAccessibleAgent(agentId: $agentId, userId: $userId);
		if ($agent === null) {
			return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->agentAccess->canUserModifyAgent(agent: $agent, userId: $userId) === false) {
			return new JSONResponse(['error' => 'Only the owner of this agent can save it as a template'], Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end saveFromAgentRefusal()

	/**
	 * Save the caller's agent as an active template.
	 *
	 * @param string $agentId The Agent UUID.
	 * @param string $userId The caller's UID.
	 *
	 * @return JSONResponse The new template (201), 404 when it could not be created, 500 on failure.
	 */
	private function saveAgentAsTemplate(string $agentId, string $userId): JSONResponse {
		try {
			$template = $this->templateService->saveAgentAsTemplate(agentId: $agentId, createdBy: $userId);
			if ($template === null) {
				return new JSONResponse(['error' => 'Agent not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq save-as-template failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not save the agent as a template'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end saveAgentAsTemplate()

	/**
	 * Export a template's own portable fields to a shareable JSON package.
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse The package string, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	public function exportPackage(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$package = $this->templateService->exportTemplate(templateId: $id);
			if ($package === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['package' => $package]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template export-package failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end exportPackage()

	/**
	 * Import a package into a new template (quarantined + content-scanned when
	 * `source` is `org`/`hub`; `active` and unscanned when `local`).
	 *
	 * @return JSONResponse The imported template, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-importing-a-template-from-an-external-source-lands-quarantined-and-content-scanned
	 */
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$package = (string)$this->request->getParam('package', '');
		$source = (string)$this->request->getParam('source', 'org');
		if (trim($package) === '') {
			return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
		}

		if (in_array($source, ['local', 'org', 'hub'], true) === false) {
			$source = 'org';
		}

		try {
			$template = $this->templateService->importPackage(package: $package, source: $source, createdBy: $user->getUID());
			return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template import failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Import failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end import()

	/**
	 * Approve a quarantined template (the review gate → active; action-auth-gated).
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse The updated template, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-approving-a-quarantined-template-requires-action-authorization
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-overriding-a-dangerous-scan-verdict-requires-a-stricter-action
	 */
	public function approve(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$force = (bool)$this->request->getParam('force', false);
		$refusal = $this->approvalRefusal(user: $user, force: $force);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->approveTemplate(id: $id, force: $force);

	}//end approve()

	/**
	 * Refuse an approval the caller may not give: approving needs
	 * `agenttemplate.approve-quarantined`, and forcing past a dangerous scan verdict
	 * additionally needs `agenttemplate.override-scan-verdict`.
	 *
	 * @param IUser $user The caller.
	 * @param bool $force Whether the caller overrides the scan verdict.
	 *
	 * @return JSONResponse|null The 403 refusal, or null when the caller may approve.
	 */
	private function approvalRefusal(IUser $user, bool $force): ?JSONResponse {
		try {
			$this->actionAuth->requireAction(user: $user, action: 'agenttemplate.approve-quarantined');
			if ($force === true) {
				// A caller who can approve a clean scan cannot necessarily override a
				// dangerous verdict — gate BEFORE calling the service with force: true.
				$this->actionAuth->requireAction(user: $user, action: 'agenttemplate.override-scan-verdict');
			}
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}

		return null;

	}//end approvalRefusal()

	/**
	 * Approve a quarantined template through the service.
	 *
	 * @param string $id The AgentTemplate UUID.
	 * @param bool $force Whether the caller overrides the scan verdict.
	 *
	 * @return JSONResponse The updated template, 409 when the scan kept it quarantined, 404 or 500.
	 */
	private function approveTemplate(string $id, bool $force): JSONResponse {
		try {
			$template = $this->templateService->approveQuarantined(templateId: $id, force: $force);
			if ($template === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return $this->approvalResponse(shaped: $this->shape(object: $template), force: $force);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template approve failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Approve failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end approveTemplate()

	/**
	 * The response to an approval: a non-forced approve leaves the template
	 * quarantined ONLY when the content scanner blocked it (a dangerous verdict), which
	 * is signalled as 409 so the UI can present the findings and offer an explicit
	 * override (mirrors SkillMarketplaceController).
	 *
	 * @param array<string, mixed> $shaped The shaped template.
	 * @param bool $force Whether the caller overrode the scan verdict.
	 *
	 * @return JSONResponse The template, or the 409 with the scan findings.
	 */
	private function approvalResponse(array $shaped, bool $force): JSONResponse {
		if (($shaped['state'] ?? '') === 'quarantined' && $force === false) {
			return new JSONResponse(
				[
					'error' => 'Approval blocked: content scan flagged dangerous patterns.',
					'scanReport' => ($shaped['scanReport'] ?? []),
					'quarantineReason' => ($shaped['quarantineReason'] ?? ''),
					'template' => $shaped,
				],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse($shaped);

	}//end approvalResponse()

	/**
	 * "Use this template" — instantiate a template into a real Agent in the caller's
	 * organisation (model coerced into the caller's effective ModelPolicy; skill refs
	 * resolved best-effort; no Schedule is ever created).
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse The instantiate result, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-instantiating-a-template-never-silently-violates-the-caller-s-model-policy
	 */
	public function instantiate(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$overrides = (array)$this->request->getParam('overrides', []);

		try {
			$organisation = $this->resolveActiveOrganisation(uid: $user->getUID());
			$result = $this->templateService->instantiate(templateId: $id, organisation: $organisation, overrides: $overrides);
			if ($result === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($result, Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template instantiate failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not create an agent from this template: ' . $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

	}//end instantiate()

	/**
	 * Search GitHub for `topic:hermiq-agent-template` repos (agent-template-github-store).
	 *
	 * Login-required (in-body 401 guard). Returns the normalised cards plus a
	 * `brokerCredentialAvailable`/`brokerUsed`/`rateLimited` hint; never exposes the raw
	 * GitHub body or any token. Degrades to HTTP 200 with an empty card list on a
	 * rate-limited/unreachable GitHub call — never a 5xx for a third-party outage.
	 *
	 * @return JSONResponse 200 with `{outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited}`; 401 anonymous.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-degrade-gracefully-when-github-is-rate-limited-or-unreachable
	 */
	public function githubSearch(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$query = $this->request->getParam('q');
		if (is_string($query) === false) {
			$query = null;
		}

		try {
			$result = $this->catalogService->search(
				query: $query,
				actingUserId: $user->getUID(),
				credentialId: $this->credentialParam()
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template github search failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(
				[
					'outcome' => GitHubTemplateCatalogService::OUTCOME_UNREACHABLE,
					'cards' => [],
					'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
					'brokerUsed' => false,
					'rateLimited' => false,
				],
				Http::STATUS_OK
			);
		}

		return new JSONResponse(
			[
				'outcome' => $result['outcome'],
				'cards' => $result['cards'],
				'brokerCredentialAvailable' => $this->catalogService->isBrokerAvailable(),
				'brokerUsed' => $result['brokerUsed'],
				'rateLimited' => $result['rateLimited'],
			],
			Http::STATUS_OK
		);

	}//end githubSearch()

	/**
	 * Install a discovered GitHub template: fetch its package file → pass it through
	 * the UNCHANGED `AgentTemplateService::importPackage(source: 'hub')` path — the
	 * resulting template lands `quarantined` + content-scanned exactly like a
	 * pasted-package hub import (agent-template-github-store's one non-negotiable
	 * property: no new quarantine/scan logic is introduced here).
	 *
	 * @return JSONResponse 201 with the created (quarantined) template; 400/401/404 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-template-through-the-existing-quarantine-gate
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-validate-repo-coordinates-before-any-github-call
	 */
	public function githubInstall(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->githubCoordinatesRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->installFromGithub(userId: $user->getUID());

	}//end githubInstall()

	/**
	 * Refuse GitHub coordinates (owner, repo, optional ref) that do not match the
	 * allowed patterns, before any GitHub call.
	 *
	 * @return JSONResponse|null The 400 refusal, or null when the coordinates are valid.
	 */
	private function githubCoordinatesRefusal(): ?JSONResponse {
		$owner = $this->stringParam(key: 'owner');
		$repo = $this->stringParam(key: 'repo');
		$ref = $this->nonEmptyStringParam(key: 'ref');
		if ($this->isValidRepo(owner: $owner, repo: $repo) === false) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return new JSONResponse(['error' => 'invalid_ref'], Http::STATUS_BAD_REQUEST);
		}

		return null;

	}//end githubCoordinatesRefusal()

	/**
	 * Fetch the requested repository's template package and import it.
	 *
	 * @param string $userId The caller's UID.
	 *
	 * @return JSONResponse 201 with the created (quarantined) template; 404 or 500 on failure.
	 */
	private function installFromGithub(string $userId): JSONResponse {
		try {
			$package = $this->catalogService->fetchTemplateFile(
				owner: $this->stringParam(key: 'owner'),
				repo: $this->stringParam(key: 'repo'),
				ref: $this->nonEmptyStringParam(key: 'ref'),
				actingUserId: $userId,
				credentialId: $this->credentialParam()
			);
			return $this->importGithubPackage(package: $package, userId: $userId);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template github install failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end installFromGithub()

	/**
	 * Import a fetched package through the unchanged hub import path, so it lands
	 * quarantined and content-scanned.
	 *
	 * @param string|null $package The fetched package, or null when it was unreachable.
	 * @param string $userId The caller's UID.
	 *
	 * @return JSONResponse 201 with the created template, or 404 when nothing was fetched.
	 */
	private function importGithubPackage(?string $package, string $userId): JSONResponse {
		if ($package === null) {
			return new JSONResponse(['error' => GitHubTemplateCatalogService::OUTCOME_UNREACHABLE], Http::STATUS_NOT_FOUND);
		}

		$template = $this->templateService->importPackage(package: $package, source: 'hub', createdBy: $userId);
		return new JSONResponse($this->shape(object: $template), Http::STATUS_CREATED);

	}//end importGithubPackage()

	/**
	 * Publish an existing template to a NEW GitHub repository tagged
	 * `topic:hermiq-agent-template`, built from `AgentTemplateSerializer::toPackage()`
	 * output (via `AgentTemplateService::exportTemplate()` — the same package shape the
	 * paste-a-package export already produces). Tenant-scoped: resolves the template
	 * through the same `AgentTemplateService::get()` path every other template action
	 * uses, so a caller cannot publish a template outside their organisation's
	 * visibility. On success, records `githubOwner`/`githubRepo`/`publishedAt` on the
	 * template — provenance only, never round-tripped through the exported package.
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return JSONResponse 201 with `{repoUrl, commitSha}`; 400/401/404/422/503 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-let-a-template-owner-publish-it-to-a-new-tagged-github-repository
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-never-hold-or-log-the-github-token
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-refuse-to-overwrite-an-existing-github-repository
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-record-github-publish-provenance-without-leaking-it-into-packages
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-scope-publish-to-templates-the-caller-can-already-see
	 */
	public function publishGithub(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$refusal = $this->publishRequestRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->publishTemplate(id: $id, userId: $user->getUID());
	}//end publishGithub()

	/**
	 * Refuse a publish request whose target repository does not match the allowed
	 * pattern (400) or that names no broker credential (422).
	 *
	 * @return JSONResponse|null The refusal, or null when the request is complete.
	 */
	private function publishRequestRefusal(): ?JSONResponse {
		if ($this->isValidRepo(owner: $this->stringParam(key: 'owner'), repo: $this->stringParam(key: 'repo')) === false) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->stringParam(key: 'credentialId') === '') {
			return new JSONResponse(['error' => 'A broker credentialId is required to publish'], Http::STATUS_UNPROCESSABLE_ENTITY);
		}

		return null;

	}//end publishRequestRefusal()

	/**
	 * Publish a template the caller can see and record the provenance on success.
	 *
	 * Tenant-scoped read: a template outside the caller's organisation's visibility
	 * is 404, identical to show()/update()'s existing behaviour (never a 403 that
	 * would confirm existence).
	 *
	 * @param string $id The AgentTemplate UUID.
	 * @param string $userId The caller's UID.
	 *
	 * @return JSONResponse 201 with `{repoUrl, commitSha}`; 404/422/500/503 on failure.
	 */
	private function publishTemplate(string $id, string $userId): JSONResponse {
		$package = $this->templateService->exportTemplate(templateId: $id);
		if ($package === null) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		if ($this->pushService->isBrokerAvailable() === false) {
			return new JSONResponse(['error' => 'The GitHub credential broker is not available'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$pushed = $this->pushPackage(package: $package, userId: $userId);
		if ($pushed instanceof JSONResponse) {
			return $pushed;
		}

		$this->recordGithubProvenance(id: $id);
		return new JSONResponse($pushed, Http::STATUS_CREATED);

	}//end publishTemplate()

	/**
	 * Push a package to the requested new repository; a refusal from the push service
	 * is answered with its own message (422), any other failure with 500.
	 *
	 * @param string $package The exported package.
	 * @param string $userId The caller's UID.
	 *
	 * @return array<string, mixed>|JSONResponse The push result, or the failure response.
	 */
	private function pushPackage(string $package, string $userId): array|JSONResponse {
		try {
			return $this->pushToGithub(package: $package, userId: $userId);
		} catch (RuntimeException $e) {
			$this->logger->warning('Hermiq agent-template github publish refused: ' . $e->getMessage());
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq agent-template github publish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Publish failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end pushPackage()

	/**
	 * Push a package to the repository, visibility and credential the request names.
	 *
	 * @param string $package The exported package.
	 * @param string $userId The caller's UID.
	 *
	 * @return array<string, mixed> The push result (`{repoUrl, commitSha}`).
	 */
	private function pushToGithub(string $package, string $userId): array {
		return $this->pushService->push(
			package: $package,
			owner: $this->stringParam(key: 'owner'),
			repo: $this->stringParam(key: 'repo'),
			visibility: $this->visibilityParam(),
			credentialId: $this->stringParam(key: 'credentialId'),
			actingUserId: $userId
		);

	}//end pushToGithub()

	/**
	 * Record `githubOwner`/`githubRepo`/`publishedAt` on a published template —
	 * provenance only, never round-tripped through the exported package.
	 *
	 * @param string $id The AgentTemplate UUID.
	 *
	 * @return void
	 */
	private function recordGithubProvenance(string $id): void {
		$this->templateService->update(
			templateId: $id,
			payload: [
				'githubOwner' => $this->stringParam(key: 'owner'),
				'githubRepo' => $this->stringParam(key: 'repo'),
				'publishedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c'),
			]
		);

	}//end recordGithubProvenance()

	/**
	 * The requested repository visibility: `public` or `private`, `private` otherwise.
	 *
	 * @return string The visibility.
	 */
	private function visibilityParam(): string {
		$visibility = (string)($this->request->getParam('visibility') ?? 'private');
		if (in_array($visibility, ['public', 'private'], true) === false) {
			return 'private';
		}

		return $visibility;

	}//end visibilityParam()

	/**
	 * Whether a GitHub owner and repository name both match the allowed pattern.
	 *
	 * @param string $owner The GitHub owner.
	 * @param string $repo The repository name.
	 *
	 * @return bool True when both are valid.
	 */
	private function isValidRepo(string $owner, string $repo): bool {
		return preg_match(self::OWNER_REPO_PATTERN, $owner) === 1 && preg_match(self::OWNER_REPO_PATTERN, $repo) === 1;

	}//end isValidRepo()

	/**
	 * A request parameter as a string, `''` when absent.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string The parameter value.
	 */
	private function stringParam(string $key): string {
		return (string)($this->request->getParam($key) ?? '');

	}//end stringParam()

	/**
	 * A request parameter when it is a non-empty string, null otherwise.
	 *
	 * @param string $key The parameter name.
	 *
	 * @return string|null The parameter value, or null.
	 */
	private function nonEmptyStringParam(string $key): ?string {
		$value = $this->request->getParam($key);
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;

	}//end nonEmptyStringParam()

	/**
	 * IDOR guard for `update()`/`destroy()`: the caller must own the template, or
	 * be an instance admin acting as custodian of a `__system__`-seeded one (the
	 * seeded starter templates have no human owner, so without the custodian arm
	 * nobody could ever curate them). Same predicate the owner-guarded skill
	 * routes already use.
	 *
	 * A refusal is a 404, never a 403 — the gallery is org-readable, but who owns
	 * a template is not something a non-owner needs confirmed.
	 *
	 * @param string $templateId The AgentTemplate UUID taken off the URL.
	 *
	 * @return bool True when the caller may write this template.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	private function requireTemplateOwnership(string $templateId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			// Read through OpenRegister directly (ADR-022/ADR-083) — the tenant-scoped
			// read defaults `_rbac`/`_multitenancy` to TRUE, so a template outside the
			// caller's tenant never reaches the ownership comparison below.
			$template = $this->objectService->find(
				id: $templateId,
				register: AgentTemplateService::REGISTER_SLUG,
				schema: AgentTemplateService::TEMPLATE_SCHEMA
			);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent and this
			// guard runs OUTSIDE the caller's try block — an escape would be a
			// framework 500 on a #[NoAdminRequired] route (gate-49).
			$this->logger->warning(
				'Hermiq agent-template lookup failed for ' . $templateId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}

		if (($template instanceof ObjectEntity) === false) {
			return false;
		}

		return $this->seedCustody->actsAsOwner(owner: $template->getOwner(), uid: $user->getUID());
	}//end requireTemplateOwnership()

	/**
	 * IDOR guard for `export()`: the package it returns carries the Agent's
	 * `systemPrompt`, tools and skill refs, so exporting is a READ of the agent —
	 * including a private one that `AgentsController::show()` would have refused.
	 *
	 * @param string $agentId The Agent UUID taken off the URL.
	 *
	 * @return bool True when the caller may read this agent.
	 *
	 * @spec openspec/specs/agent-template-gallery/spec.md#requirement-an-agenttemplate-carries-no-secrets-and-no-tenant-data
	 */
	private function requireAgentReadAccess(string $agentId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->agentAccess->loadAccessibleAgent(
			agentId: $agentId,
			userId: $user->getUID()
		) !== null;

	}//end requireAgentReadAccess()

	/**
	 * Read the optional `credentialId` request param (broker upgrade) for the GitHub
	 * search/install endpoints — mirrors OpenBuild's `ShopController::credentialParam()`.
	 *
	 * @return string|null The credential UUID, or null when absent.
	 */
	private function credentialParam(): ?string {
		return $this->nonEmptyStringParam(key: 'credentialId');
	}//end credentialParam()

	/**
	 * Shape an AgentTemplate ObjectEntity into a UUID + payload response map.
	 *
	 * @param ObjectEntity $object The template object.
	 *
	 * @return array<string, mixed> The response payload.
	 */
	private function shape(ObjectEntity $object): array {
		$data = $object->getObject();
		$data['uuid'] = (string)$object->getUuid();
		return $data;
	}//end shape()

	/**
	 * Resolve the calling user's active organisation for instantiate (identity from
	 * session — no request parameter accepted, so a caller can never target another
	 * organisation's ModelPolicy). Falls back to '' (the instance-wide default scope)
	 * when the user has no active/default organisation (mirrors
	 * GuardrailPolicyController::resolveActiveOrganisation()).
	 *
	 * @param string $uid The requesting user's id.
	 *
	 * @return string The organisation identifier, or '' when none resolves.
	 */
	private function resolveActiveOrganisation(string $uid): string {
		try {
			// OpenRegister's OrganisationMapper declares
			// getActiveOrganisationWithFallback() on both `main` and
			// `development`, so the method_exists() probe this replaces could
			// never be false.
			return (string)($this->organisationMapper->getActiveOrganisationWithFallback($uid) ?? '');
		} catch (Throwable $e) {
			$this->logger->warning('Hermiq could not resolve active organisation: ' . $e->getMessage(), ['exception' => $e]);
		}

		return '';
	}//end resolveActiveOrganisation()
}//end class
