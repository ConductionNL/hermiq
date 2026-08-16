<?php

/**
 * Hermiq SkillController.
 *
 * Browse the tenant-scoped skills catalog, import/export agentskills.io packages, and
 * install a skill onto an agent. All reads/writes run in the caller's session context
 * through SkillService → OpenRegister ObjectService, so OR's native RBAC denies
 * cross-tenant access. `@NoAdminRequired` opens the routes to any authenticated user.
 *
 * ⚠️ Tenancy is NOT the guard on the three routes that MUTATE. The catalog is
 * deliberately org-readable, so `index` hands every authenticated user the full
 * list of skill uuids, and the `Skill` schema declares no `authorization` block,
 * which leaves OpenRegister's register RBAC default-OPEN on it. Therefore:
 * `update()` is OWNER-guarded (`SeedCustodyService::actsAsOwner()`, the predicate
 * `SkillMaturityController::qualify` already uses) because a skill body is folded
 * into the system-prompt preamble of every run of every agent that installed it;
 * and `install()`/`uninstall()` are guarded on the TARGET AGENT
 * (`AgentAccessService::loadModifiableAgent()`), because installing a colleague's
 * skill onto your own agent is legitimate while installing anything onto theirs is
 * not. Every refusal is a 404, never a 403.
 *
 * hermiq-github-store adds two GitHub-store endpoints on this SAME controller
 * (catalog/discovery operations, mirroring how `import`/`export` already live here):
 * `githubSearch()` + `githubInstall()`, a close port of
 * `AgentTemplateController::githubSearch()`/`::githubInstall()` scoped to
 * `GitHubTemplateCatalogService::KIND_SKILL`. `githubInstall()` is a thin adapter in
 * front of the UNCHANGED `SkillMarketplaceService::installFromSource(source: 'hub')`
 * — no new quarantine/scan logic (design.md Decision 2). The GitHub token never
 * enters Hermiq: both endpoints only ever pass a broker `credentialId` to
 * `GitHubTemplateCatalogService`.
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
 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
 */

declare(strict_types=1);

namespace OCA\Hermiq\Controller;

use OCA\Hermiq\AppInfo\Application;
use OCA\Hermiq\Service\AgentAccessService;
use OCA\Hermiq\Service\GitHubTemplateCatalogService;
use OCA\Hermiq\Service\GitHubTemplatePushService;
use OCA\Hermiq\Service\SeedCustodyService;
use OCA\Hermiq\Service\SkillBundleInstaller;
use OCA\Hermiq\Service\SkillBundleSerializer;
use OCA\Hermiq\Service\SkillMarketplaceService;
use OCA\Hermiq\Service\SkillService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Tenant-scoped skills catalog endpoints.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)     One public method per registered
 *   route. The count tracks the skills API surface (catalog CRUD, GitHub store
 *   search/install, bundle publish/install); collapsing routes into fewer methods
 *   to satisfy the metric would hide the surface rather than reduce it.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Sum of many small
 *   guard-then-delegate route methods — every branch is an input-validation guard
 *   returning a shaped error, not domain logic. The domain work lives in
 *   SkillService / SkillMarketplaceService / SkillBundleSerializer.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   One injected collaborator per
 *   seam (catalog, marketplace, bundle serialiser, push service) plus the response
 *   and exception types the routes return.
 *
 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
 */
class SkillController extends Controller {
	/**
	 * Safe GitHub owner/repo pattern (hermiq-github-store), validated before any
	 * path interpolation on the search/install endpoints — mirrors
	 * `AgentTemplateController::OWNER_REPO_PATTERN` exactly.
	 *
	 * @var string
	 */
	private const OWNER_REPO_PATTERN = '/^[A-Za-z0-9._-]{1,100}$/';

	/**
	 * Safe git-ref pattern (hermiq-github-store).
	 *
	 * @var string
	 */
	private const REF_PATTERN = '/^[A-Za-z0-9._\/-]{1,255}$/';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object.
	 * @param SkillService $skillService The skill read/write path.
	 * @param IUserSession $userSession Resolves the requesting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 * @param GitHubTemplateCatalogService $catalogService GitHub search/fetch (hermiq-github-store).
	 * @param SkillMarketplaceService $marketplaceService Quarantine install path (hermiq-github-store).
	 * @param SkillBundleSerializer $bundleSerializer Bundle tree (de)serialiser (skill-bundle-publish).
	 * @param GitHubTemplatePushService $pushService Bundle publish (skill-bundle-publish).
	 * @param SkillBundleInstaller $bundleInstaller Bundle install, shared with OpenBuild (apply-v2-channels).
	 * @param SeedCustodyService $seedCustody Owner-or-seed-custodian check (skill write guard).
	 * @param AgentAccessService $agentAccess Per-agent authorization (install/uninstall guard).
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI: each parameter is a
	 *   distinct injected collaborator, not a logic-bearing argument list.
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
	 */
	public function __construct(
		IRequest $request,
		private readonly SkillService $skillService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly GitHubTemplateCatalogService $catalogService,
		private readonly SkillMarketplaceService $marketplaceService,
		private readonly SkillBundleSerializer $bundleSerializer,
		private readonly GitHubTemplatePushService $pushService,
		private readonly SkillBundleInstaller $bundleInstaller,
		private readonly SeedCustodyService $seedCustody,
		private readonly AgentAccessService $agentAccess,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the tenant's skills.
	 *
	 * @return JSONResponse The skills list, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
	 */
	public function index(): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$skills = array_map([$this, 'shape'], $this->skillService->listSkills());
			return new JSONResponse(['results' => $skills]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skills list failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Could not load skills'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end index()

	/**
	 * Import an agentskills.io package into a new Skill.
	 *
	 * @return JSONResponse The imported skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
	 */
	public function import(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$package = (string)$this->request->getParam('package', '');
		if (trim($package) === '') {
			return new JSONResponse(['error' => 'A non-empty package is required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$skill = $this->skillService->importSkill(package: $package, createdBy: $user->getUID());
			return new JSONResponse($this->shape(object: $skill));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill import failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Import failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end import()

	/**
	 * Export a Skill back to an agentskills.io package.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The package string, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
	 */
	public function export(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$package = $this->skillService->exportSkill(skillId: $id);
			if ($package === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse(['package' => $package]);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill export failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Export failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end export()

	/**
	 * Update a Skill from the edit form's merge payload (skill-maturity). The service
	 * applies the computed-maturity write guard: client-supplied `maturityLevel` /
	 * `levelEvidence.l1`–`l4` are ignored and stored values carried forward, while
	 * `targetLevel` and ordinary fields stay editable.
	 *
	 * ⚠️ OWNER-GUARDED. A skill body is not an inert document: its text is folded
	 * into the system-prompt preamble of every run of every agent that installed
	 * it (`Engine::assembleSkillsForRun()`), so an unguarded write here is
	 * persistent, fan-out prompt injection into other users' agents rather than a
	 * one-shot edit. The catalog is deliberately org-readable, which hands every
	 * authenticated user the list of targets — so visibility can never be the
	 * write guard. Same predicate `SkillMaturityController::qualify` already
	 * uses.
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The updated skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-maturitylevel-and-computed-evidence-are-never-client-writable
	 */
	public function update(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->requireSkillOwnership(skillId: $id) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		$params = $this->request->getParams();
		$data = [];
		foreach ($params as $key => $value) {
			if (is_string($key) === true && $key !== 'id' && $key !== '_route') {
				$data[$key] = $value;
			}
		}

		try {
			$skill = $this->skillService->updateSkill(skillId: $id, data: $data);
			if ($skill === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->shape(object: $skill));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill update failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end update()

	/**
	 * Install a Skill onto an agent (records the agent on installedOn).
	 *
	 * @param string $id The Skill UUID.
	 *
	 * @return JSONResponse The updated skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skills-catalog/tasks.md#4-controller-routes
	 */
	public function install(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$agentId = trim((string)$this->request->getParam('agentId', ''));
		if ($agentId === '') {
			return new JSONResponse(['error' => 'An agentId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->requireAgentOwnership(agentId: $agentId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$skill = $this->skillService->installOnAgent(skillId: $id, agentId: $agentId);
			if ($skill === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->shape(object: $skill));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill install failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end install()

	/**
	 * Detach a Skill from an agent (removes the agent from installedOn).
	 *
	 * @param string $id The Skill UUID.
	 * @param string $agentId The agent UUID (route param).
	 *
	 * @return JSONResponse The updated skill, or an error status.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/agent-capability-detail-surface/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
	 */
	public function uninstall(string $id, string $agentId): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($agentId) === '') {
			return new JSONResponse(['error' => 'An agentId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->requireAgentOwnership(agentId: $agentId) === false) {
			return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
		}

		try {
			$skill = $this->skillService->uninstallFromAgent(skillId: $id, agentId: $agentId);
			if ($skill === null) {
				return new JSONResponse(['error' => 'Not found'], Http::STATUS_NOT_FOUND);
			}

			return new JSONResponse($this->shape(object: $skill));
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill uninstall failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Uninstall failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

	}//end uninstall()

	/**
	 * Search GitHub for `topic:hermiq-skill` repos (hermiq-github-store) — a close
	 * port of `AgentTemplateController::githubSearch()` scoped to
	 * `GitHubTemplateCatalogService::KIND_SKILL`.
	 *
	 * Login-required (in-body 401 guard). Returns the normalised, kind-tagged cards
	 * plus a `brokerCredentialAvailable`/`brokerUsed`/`rateLimited` hint; never
	 * exposes the raw GitHub body or any token. Degrades to HTTP 200 with an empty
	 * card list on a rate-limited/unreachable GitHub call — never a 5xx for a
	 * third-party outage.
	 *
	 * @return JSONResponse 200 with `{outcome, cards, brokerCredentialAvailable, brokerUsed, rateLimited}`; 401 anonymous.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-provide-a-server-backed-search-for-hermiq-agent-template-repos
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
				credentialId: $this->credentialParam(),
				kind: GitHubTemplateCatalogService::KIND_SKILL
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill github search failed: ' . $e->getMessage(), ['exception' => $e]);
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
	 * Install a discovered GitHub skill: fetch its agentskills.io package file →
	 * pass it through the UNCHANGED `SkillMarketplaceService::
	 * installFromSource(source: 'hub')` path (design.md Decision 2) — the resulting
	 * skill lands `state: "quarantined"` + content-scanned exactly like an
	 * OpenConnector hub install. No new quarantine/scan logic is introduced here.
	 *
	 * @return JSONResponse 201 with the created (quarantined) skill; 400/401/404 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/specs/agent-template-github-store/spec.md#requirement-the-system-must-install-a-discovered-skill-through-the-skill-quarantine-gate
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Sequential input-validation guards
	 *   (auth, owner/repo/ref patterns, fetch outcome) each add a branch; the flow
	 *   itself is a straight guard-then-delegate path.
	 */
	public function githubInstall(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$owner = (string)($this->request->getParam('owner') ?? '');
		$repo = (string)($this->request->getParam('repo') ?? '');
		$refRaw = $this->request->getParam('ref');
		$ref = null;
		if (is_string($refRaw) === true && $refRaw !== '') {
			$ref = $refRaw;
		}

		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return new JSONResponse(['error' => 'invalid_ref'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$package = $this->catalogService->fetchPackageFile(
				kind: GitHubTemplateCatalogService::KIND_SKILL,
				owner: $owner,
				repo: $repo,
				ref: $ref,
				actingUserId: $user->getUID(),
				credentialId: $this->credentialParam()
			);
			if ($package === null) {
				return new JSONResponse(['error' => GitHubTemplateCatalogService::OUTCOME_UNREACHABLE], Http::STATUS_NOT_FOUND);
			}

			// Skill-package-multifile: a published skill repo carries its auxiliary
			// files as sibling blobs. Fetching only the package file reconstructed a
			// bare SKILL.md and silently dropped every references/ and learnings.md
			// entry — install must mirror what publish emitted.
			$auxFiles = $this->catalogService->fetchAuxFiles(
				kind: GitHubTemplateCatalogService::KIND_SKILL,
				owner: $owner,
				repo: $repo,
				ref: $ref,
				actingUserId: $user->getUID(),
				credentialId: $this->credentialParam()
			);

			$skill = $this->marketplaceService->installFromSource(
				package: $package,
				source: 'hub',
				createdBy: $user->getUID(),
				auxFiles: $auxFiles
			);
			return new JSONResponse($this->shape(object: $skill), Http::STATUS_CREATED);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill github install failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'Install failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}//end try

	}//end githubInstall()

	/**
	 * IDOR guard for the skill WRITE path: the caller must own the skill, or be
	 * an instance admin acting as custodian of a system-seeded one. Exactly the
	 * predicate `SkillMaturityController::loadOwnedSkill()` and
	 * `SkillVersionController` already apply to the same objects — this endpoint
	 * simply never called it.
	 *
	 * A refusal is a 404, never a 403: the catalog is org-readable, but who owns
	 * a given skill is not something a non-owner needs confirmed.
	 *
	 * @param string $skillId The Skill UUID taken off the URL.
	 *
	 * @return bool True when the caller may write this skill.
	 *
	 * @spec openspec/specs/skill-maturity/spec.md#requirement-the-qualify-endpoint-is-owner-guarded-and-returns-a-scorecard
	 */
	private function requireSkillOwnership(string $skillId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		try {
			$skill = $this->skillService->getSkill(skillId: $skillId);
		} catch (Throwable $e) {
			// `ObjectService::find()` throws when the object is absent, and this
			// guard runs OUTSIDE the caller's try block — an escape would be a
			// framework 500 on a #[NoAdminRequired] route (gate-49).
			$this->logger->warning(
				'Hermiq skill lookup failed for ' . $skillId . ': ' . $e->getMessage(),
				['exception' => $e]
			);
			return false;
		}

		if (($skill instanceof ObjectEntity) === false) {
			return false;
		}

		return $this->seedCustody->actsAsOwner(owner: $skill->getOwner(), uid: $user->getUID());
	}//end requireSkillOwnership()

	/**
	 * IDOR guard for install/uninstall: the guard belongs on the AGENT, not the
	 * skill. Installing a colleague's published skill onto MY agent is the whole
	 * point of an org-readable catalog; installing ANY skill onto SOMEBODY
	 * ELSE'S agent is the attack, because an installed skill's text joins that
	 * agent's system-prompt preamble on its next run.
	 *
	 * @param string $agentId The agent UUID supplied by the caller.
	 *
	 * @return bool True when the caller owns the target agent.
	 *
	 * @spec openspec/specs/skills-catalog/spec.md#requirement-browse-and-install-skills-into-an-agent
	 * @spec openspec/specs/skills-catalog/spec.md#requirement-detach-an-installed-skill-from-an-agent
	 */
	private function requireAgentOwnership(string $agentId): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		return $this->agentAccess->loadModifiableAgent(
			agentId: $agentId,
			userId: $user->getUID()
		) !== null;

	}//end requireAgentOwnership()

	/**
	 * Read the optional `credentialId` request param (broker upgrade) for the
	 * GitHub search/install endpoints — mirrors
	 * `AgentTemplateController::credentialParam()`.
	 *
	 * @return string|null The credential UUID, or null when absent.
	 */
	private function credentialParam(): ?string {
		$credentialId = $this->request->getParam('credentialId');
		if (is_string($credentialId) === true && $credentialId !== '') {
			return $credentialId;
		}

		return null;
	}//end credentialParam()

	/**
	 * Shape a Skill ObjectEntity into a UUID + payload response map.
	 *
	 * @param ObjectEntity $object The skill object.
	 *
	 * @return array<string, mixed> The response payload.
	 */
	private function shape(ObjectEntity $object): array {
		$data = $object->getObject();
		$data['uuid'] = (string)$object->getUuid();
		return $data;
	}//end shape()

	/**
	 * Publish a SET of skills to one bundle repository (skill-bundle-publish).
	 *
	 * Each skill's files go through `SkillService::publishFileSelection()`, the one
	 * publish-time selection that strips `learning-candidates.md` — inherited, not
	 * re-implemented, so unvetted observations never leave the instance by way of a
	 * bundle any more than they do by way of a single publish.
	 *
	 * @return JSONResponse 200 with repoUrl/commitSha/created + per-skill outcomes.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	public function bundlePublish(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$owner = (string)($this->request->getParam('owner') ?? '');
		$repo = (string)($this->request->getParam('repo') ?? '');
		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		$skillIds = $this->request->getParam('skillIds');
		$agentIds = $this->request->getParam('agentIds');
		if (is_array($agentIds) === false) {
			$agentIds = [];
		}

		if (is_array($skillIds) === false) {
			$skillIds = [];
		}

		if ($skillIds === [] && $agentIds === []) {
			return new JSONResponse(['error' => 'skillIds or agentIds must be a non-empty array'], Http::STATUS_BAD_REQUEST);
		}

		$visibility = (string)($this->request->getParam('visibility') ?? 'private');
		if (in_array($visibility, ['public', 'private'], true) === false) {
			return new JSONResponse(['error' => 'invalid_visibility'], Http::STATUS_BAD_REQUEST);
		}

		$collected = $this->collectPublishablePayloads(skillIds: $skillIds);
		$payloads = $collected['payloads'];
		$outcomes = $collected['outcomes'];

		$collectedAgents = $this->collectPublishableAgentPayloads(agentIds: $agentIds, userId: $user->getUID());
		$agentPayloads = $collectedAgents['payloads'];
		$agentOutcomes = $collectedAgents['outcomes'];

		if ($payloads === [] && $agentPayloads === []) {
			return new JSONResponse(
				['error' => 'no_publishable_content', 'skills' => $outcomes, 'agents' => $agentOutcomes],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			// `$dropped` is read back below and folded into the per-skill outcomes.
			// Reporting `published` for a skill the serialiser discarded is how the
			// first real bundle claimed 94 while shipping 64 — the response must be
			// built from what was SERIALISED, not from what was requested.
			$dropped = [];
			$droppedAgents = [];
			$tree = $this->bundleSerializer->toBundle(skills: $payloads, dropped: $dropped, agents: $agentPayloads, droppedAgents: $droppedAgents);

			$result = $this->pushService->publishBundle(
				files: $tree,
				owner: $owner,
				repo: $repo,
				visibility: $visibility,
				credentialId: (string)($this->credentialParam() ?? ''),
				actingUserId: $user->getUID()
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill bundle publish failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'publish_failed'], Http::STATUS_BAD_GATEWAY);
		}

		$reconciled = $this->reconcileOutcomes(outcomes: $outcomes, dropped: $dropped);
		$reconciledAgents = $this->reconcileOutcomes(outcomes: $agentOutcomes, dropped: $droppedAgents);

		return new JSONResponse(
			[
				'repoUrl' => $result['repoUrl'],
				'commitSha' => $result['commitSha'],
				'created' => $result['created'],
				'published' => $reconciled['published'],
				'dropped' => count($dropped),
				'truncated' => ($dropped !== [] || $droppedAgents !== []),
				'skills' => $reconciled['outcomes'],
				'agentsPublished' => $reconciledAgents['published'],
				'agentsDropped' => count($droppedAgents),
				'agents' => $reconciledAgents['outcomes'],
			],
			Http::STATUS_OK
		);

	}//end bundlePublish()

	/**
	 * Install every skill from a bundle repository (skill-bundle-publish).
	 *
	 * Fans out to the UNCHANGED `installFromSource()` — one call per skill — so
	 * quarantine and per-skill content scanning are INHERITED rather than re-proved.
	 * A bundle is a delivery mechanism, never a trust assertion: installing N skills
	 * yields N quarantined skills a reviewer must still clear individually.
	 *
	 * A partial failure is a 200 carrying a non-zero `failed` count, not a blanket
	 * 500 — installing 93 of 94 skills is a materially different result from
	 * installing none, and collapsing both into "error" would hide which is which.
	 *
	 * @return JSONResponse 200 with per-skill outcomes; 400/401/404 on failure.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
	 */
	public function bundleInstall(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$owner = (string)($this->request->getParam('owner') ?? '');
		$repo = (string)($this->request->getParam('repo') ?? '');
		$refRaw = $this->request->getParam('ref');
		$ref = null;
		if (is_string($refRaw) === true && $refRaw !== '') {
			$ref = $refRaw;
		}

		$invalid = $this->rejectBadCoordinates(owner: $owner, repo: $repo, ref: $ref);
		if ($invalid !== null) {
			return $invalid;
		}

		try {
			$bundle = $this->catalogService->fetchBundle(
				owner: $owner,
				repo: $repo,
				ref: $ref,
				actingUserId: $user->getUID(),
				credentialId: $this->credentialParam()
			);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq skill bundle fetch failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'fetch_failed'], Http::STATUS_BAD_GATEWAY);
		}

		if ($bundle === null) {
			return new JSONResponse(['error' => 'not_a_bundle'], Http::STATUS_NOT_FOUND);
		}

		try {
			$parsed = $this->bundleSerializer->fromBundle(files: $bundle['files']);
		} catch (Throwable $e) {
			$this->logger->error('Hermiq bundle install: parse failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['error' => 'not_a_bundle'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->installBundleSkills(
			parsed: $parsed,
			createdBy: $user->getUID(),
			owner: $owner,
			repo: $repo
		);

		// This direct HTTP path predates the skill-bundle agents extension and,
		// unlike ApplicationsController's shop-install -> SkillChannelDelegate ->
		// SkillBundleInstaller::installFromRepo() route, never called
		// agentsFromBundle()/installAgents() — a bundle installed straight
		// through this endpoint silently dropped every agent it declared.
		$agents = $this->bundleSerializer->agentsFromBundle(files: $bundle['files']);
		$agentResult = $this->bundleInstaller->installAgents(parsed: $agents);

		return new JSONResponse(
			[
				'installed' => $result['counts']['installed'],
				'updated' => $result['counts']['updated'],
				'unchanged' => $result['counts']['unchanged'],
				'skipped' => $result['counts']['skipped'],
				'failed' => $result['counts']['failed'],
				'truncated' => $bundle['truncated'],
				'skills' => $result['outcomes'],
				'agents' => $agentResult['outcomes'],
			],
			Http::STATUS_OK
		);

	}//end bundleInstall()

	/**
	 * Reject invalid repo coordinates BEFORE any outbound GitHub call.
	 *
	 * Shared by the bundle routes so the owner/repo/ref patterns are enforced in
	 * one place — three copies of a security-relevant guard is three places for it
	 * to drift.
	 *
	 * @param string $owner The repo owner.
	 * @param string $repo The repo name.
	 * @param string|null $ref The optional git ref.
	 *
	 * @return JSONResponse|null A 400 when the coordinates are unusable, else null.
	 *
	 * @spec openspec/changes/skill-bundle-publish/contract.md
	 */
	private function rejectBadCoordinates(string $owner, string $repo, ?string $ref): ?JSONResponse {
		if (preg_match(self::OWNER_REPO_PATTERN, $owner) !== 1 || preg_match(self::OWNER_REPO_PATTERN, $repo) !== 1) {
			return new JSONResponse(['error' => 'invalid_repo'], Http::STATUS_BAD_REQUEST);
		}

		if ($ref !== null && preg_match(self::REF_PATTERN, $ref) !== 1) {
			return new JSONResponse(['error' => 'invalid_ref'], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}//end rejectBadCoordinates()

	/**
	 * Resolve the requested skill ids into publishable payloads.
	 *
	 * Each skill's files go through `SkillService::publishFileSelection()` — the one
	 * publish-time selection that strips `learning-candidates.md` — so unvetted
	 * observations never leave the instance by way of a bundle any more than they
	 * do by way of a single publish.
	 *
	 * Per-skill error handling is deliberate: `SkillService::getSkill()` delegates
	 * to `ObjectService::find()`, which THROWS `DoesNotExistException` for a missing
	 * id rather than returning null despite its `?ObjectEntity` return type. Caught
	 * per skill, one bad id is reported as `not_found` for that entry instead of
	 * failing the whole publish.
	 *
	 * @param array<int, mixed> $skillIds The requested skill ids.
	 *
	 * @return array{payloads:array<int,array<string,mixed>>,outcomes:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function collectPublishablePayloads(array $skillIds): array {
		$payloads = [];
		$outcomes = [];

		foreach ($skillIds as $skillId) {
			$id = (string)$skillId;

			try {
				$skill = $this->skillService->getSkill(skillId: $id);
			} catch (DoesNotExistException $e) {
				$outcomes[] = ['name' => $id, 'outcome' => 'not_found'];
				continue;
			} catch (Throwable $e) {
				$this->logger->error(
					'Hermiq bundle publish: resolving skill "' . $id . '" failed: ' . $e->getMessage(),
					['exception' => $e]
				);
				$outcomes[] = ['name' => $id, 'outcome' => 'failed'];
				continue;
			}//end try

			if ($skill === null) {
				$outcomes[] = ['name' => $id, 'outcome' => 'not_found'];
				continue;
			}

			$object = $skill->getObject();
			$object['files'] = ($this->skillService->publishFileSelection(skillId: $id) ?? []);

			$payloads[] = $object;
			$outcomes[] = [
				'name' => (string)($object['name'] ?? ''),
				'files' => count($object['files']),
				'outcome' => 'published',
			];
		}//end foreach

		return ['payloads' => $payloads, 'outcomes' => $outcomes];
	}//end collectPublishablePayloads()

	/**
	 * Resolve `agentIds[]` into publishable agent payloads (skill-bundle-publish
	 * §agents extension). Sibling to {@see collectPublishablePayloads()}.
	 *
	 * Gated on {@see AgentAccessService::loadAccessibleAgent()} — READ access,
	 * not modify. Publishing reads the agent and writes to an external repo; it
	 * never changes the live object, so the owner-only bar `update()` needs is
	 * the wrong one here. It would also make system-owned agents (owner
	 * `__system__`, e.g. hydra's seeded Triage agent) permanently unpublishable
	 * by any real user, since no user id ever equals `__system__`. Non-private
	 * agents are org-readable per `canUserAccessAgent()`, matching what any
	 * caller could already see by opening the agent in the Hermiq UI.
	 *
	 * @param array<int,mixed> $agentIds The requested agent uuids.
	 * @param string $userId The acting user, for the read-access check.
	 *
	 * @return array{payloads:array<int,array<string,mixed>>,outcomes:array<int,array<string,mixed>>}
	 */
	private function collectPublishableAgentPayloads(array $agentIds, string $userId): array {
		$payloads = [];
		$outcomes = [];

		foreach ($agentIds as $agentId) {
			$id = (string)$agentId;

			try {
				$agent = $this->agentAccess->loadAccessibleAgent(agentId: $id, userId: $userId);
			} catch (Throwable $e) {
				$this->logger->error(
					'Hermiq bundle publish: resolving agent "' . $id . '" failed: ' . $e->getMessage(),
					['exception' => $e]
				);
				$outcomes[] = ['name' => $id, 'outcome' => 'failed'];
				continue;
			}

			if ($agent === null) {
				$outcomes[] = ['name' => $id, 'outcome' => 'not_found'];
				continue;
			}

			$object = $agent->getObject();
			$payloads[] = $object;
			$outcomes[] = [
				'name' => (string)($object['name'] ?? ''),
				'outcome' => 'published',
			];
		}//end foreach

		return ['payloads' => $payloads, 'outcomes' => $outcomes];
	}//end collectPublishableAgentPayloads()

	/**
	 * Reconcile the per-skill outcomes against what the serialiser actually
	 * bundled, so the response describes the ARTEFACT rather than the request.
	 *
	 * A skill the serialiser discarded is re-marked `dropped` with its reason, and
	 * only skills that reached the tree count as published. Without this the API
	 * reports success for content it never shipped — observed on the first real
	 * bundle, where 94 skills were requested, 64 were bundled, and all 94 came back
	 * as `published`.
	 *
	 * @param array<int, array<string, mixed>> $outcomes The per-skill outcomes built from the request.
	 * @param array<int, array<string, mixed>> $dropped The serialiser's dropped list.
	 *
	 * @return array{outcomes:array<int,array<string,mixed>>,published:int}
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-many-skills-publish-to-a-single-repository
	 */
	private function reconcileOutcomes(array $outcomes, array $dropped): array {
		$byName = [];
		foreach ($dropped as $entry) {
			$byName[(string)($entry['name'] ?? '')] = (string)($entry['reason'] ?? 'dropped');
		}

		$published = 0;
		$out = [];
		foreach ($outcomes as $outcome) {
			$name = (string)($outcome['name'] ?? '');
			if (isset($byName[$name]) === true) {
				$outcome['outcome'] = 'dropped';
				$outcome['reason'] = $byName[$name];
			}

			if (($outcome['outcome'] ?? '') === 'published') {
				$published++;
			}

			$out[] = $outcome;
		}

		return ['outcomes' => $out, 'published' => $published];
	}//end reconcileOutcomes()

	/**
	 * Install every parsed bundle entry through the UNCHANGED per-skill path.
	 *
	 * Delegates to SkillBundleInstaller so that this HTTP route and OpenBuild's
	 * cross-app caller run the SAME implementation. The install logic used to live
	 * here as a private method, which made being an HTTP request the only way to
	 * install a bundle — and would have forced OpenBuild to reimplement skill
	 * installation, splitting frontmatter fidelity and the ADR-068 aux-file rules
	 * across two copies.
	 *
	 * @param array<int, array<string, mixed>> $parsed The parsed bundle entries.
	 * @param string $createdBy The installing user id.
	 * @param string $owner Repo owner, for skill identity.
	 * @param string $repo Repo name, for skill identity.
	 *
	 * @return array{outcomes:array<int,array<string,mixed>>,counts:array<string,int>}
	 *
	 * @spec openspec/changes/skill-bundle-publish/specs/skills-marketplace/spec.md#requirement-a-bundle-installs-as-many-individually-quarantined-skills
	 */
	private function installBundleSkills(array $parsed, string $createdBy, string $owner = '', string $repo = ''): array {
		return $this->bundleInstaller->installParsed(
			parsed: $parsed,
			createdBy: $createdBy,
			owner: $owner,
			repo: $repo
		);

	}//end installBundleSkills()
}//end class
